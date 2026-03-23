<?php
/*
Plugin Name: Яндекс Доставка для WooCommerce
Plugin URI: https://github.com/al-nemirov/yandex-delivery-woocommerce
Description: Интеграция WooCommerce с Яндекс Доставкой: расчёт стоимости, выбор ПВЗ, выгрузка заказов, автоматическая синхронизация статусов
Version: 2.2.1
Author: Al Nemirov
Author URI: https://github.com/al-nemirov
License: GPLv2 or later
Text Domain: yandex-dostavka
Domain Path: /lang
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// GitHub Auto-Updater: проверяет новые релизы на GitHub и предлагает
// обновление прямо в админке WordPress (Плагины → Обновления).
// Работает через GitHub Releases API — создай Release с тегом v2.2.0 и т.д.
// ---------------------------------------------------------------------------
add_filter( 'pre_set_site_transient_update_plugins', 'yd_github_check_update' );
add_filter( 'plugins_api', 'yd_github_plugin_info', 10, 3 );

function yd_github_check_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( __FILE__ ); // yandex-dostavka/yandex-dostavka.php
    $plugin_data = get_plugin_data( __FILE__ );
    $current_ver = $plugin_data['Version'];

    // Запрашиваем последний релиз с GitHub (кэш 12 часов)
    $cache_key = 'yd_github_release';
    $release   = get_transient( $cache_key );

    if ( false === $release ) {
        $response = wp_remote_get( 'https://api.github.com/repos/al-nemirov/yandex-delivery-woocommerce/releases/latest', array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return $transient;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        set_transient( $cache_key, $release, 12 * HOUR_IN_SECONDS );
    }

    if ( empty( $release['tag_name'] ) ) {
        return $transient;
    }

    // tag_name: "v2.2.0" → "2.2.0"
    $github_ver = ltrim( $release['tag_name'], 'v' );

    if ( version_compare( $github_ver, $current_ver, '>' ) ) {
        // Ищем zip-архив в assets релиза, fallback на zipball
        $download_url = $release['zipball_url'];
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( substr( $asset['name'], -4 ) === '.zip' ) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $transient->response[ $plugin_slug ] = (object) array(
            'slug'        => dirname( $plugin_slug ),
            'plugin'      => $plugin_slug,
            'new_version' => $github_ver,
            'url'         => $release['html_url'],
            'package'     => $download_url,
        );
    }

    return $transient;
}

function yd_github_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' ) {
        return $result;
    }

    if ( ! isset( $args->slug ) || $args->slug !== 'yandex-dostavka' ) {
        return $result;
    }

    $plugin_data = get_plugin_data( __FILE__ );
    $release     = get_transient( 'yd_github_release' );

    if ( empty( $release ) ) {
        return $result;
    }

    return (object) array(
        'name'          => $plugin_data['Name'],
        'slug'          => 'yandex-dostavka',
        'version'       => ltrim( $release['tag_name'], 'v' ),
        'author'        => $plugin_data['Author'],
        'homepage'      => $plugin_data['PluginURI'],
        'download_link' => $release['zipball_url'],
        'sections'      => array(
            'description' => $plugin_data['Description'],
            'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
        ),
    );
}

// Yandex Delivery API client
require_once __DIR__ . '/includes/class-yandex-delivery-api.php';

// ---------------------------------------------------------------------------
// P0: Единые функции для расчёта оценочной стоимости и габаритов.
// Используются и в calculate_shipping (корзина), и в create_request (заказ).
// ---------------------------------------------------------------------------

/**
 * Рассчитать оценочную стоимость груза для API Яндекс Доставки.
 *
 * API pricing-calculator и request/create ожидают одинаковые единицы.
 * По документации total_assessed_price — целые копейки (minor units).
 *
 * @param  array  $items  Массив [ 'line_total' => float, 'line_tax' => float ] или WC_Order_Item_Product[].
 * @param  string $source 'cart' или 'order' — определяет, как извлекаются поля.
 * @return int    Оценочная стоимость в копейках.
 */
function yd_assessed_price_minor_units( $items, $source = 'cart' ) {
    $sum = 0;
    foreach ( $items as $item ) {
        if ( $source === 'cart' ) {
            $sum += (float) $item['line_total'];
            $sum += (float) $item['line_tax'];
        } else {
            // WC_Order_Item_Product
            $sum += (float) $item->get_total();
            $sum += (float) $item->get_total_tax();
        }
    }
    return (int) round( $sum * 100 );
}

/**
 * Коэффициенты для конвертации в граммы и сантиметры (внутренние единицы API).
 *
 * @return array [ 'weight_c' => float, 'dimension_c' => float ]
 */
function yd_unit_coefficients() {
    $weightC    = 1; // по умолчанию граммы
    $weightUnit = strtolower( get_option( 'woocommerce_weight_unit' ) );
    if ( $weightUnit === 'kg' ) {
        $weightC = 1000;
    }
    $dimensionC    = 1; // по умолчанию сантиметры
    $dimensionUnit = strtolower( get_option( 'woocommerce_dimension_unit' ) );
    switch ( $dimensionUnit ) {
        case 'm':
            $dimensionC = 100;
            break;
        case 'mm':
            $dimensionC = 0.1;
            break;
    }
    return array( 'weight_c' => $weightC, 'dimension_c' => $dimensionC );
}

/**
 * Единая функция расчёта габаритов и веса посылки.
 *
 * Метод «стопка»: глубина/ширина = max среди товаров, высота = сумма.
 * Используется единообразно и в calculate_shipping, и в create_request.
 *
 * @param  array $products Массив [ 'product' => WC_Product, 'quantity' => int, 'variation_id' => int ].
 * @param  array $opts {
 *     @type float $default_weight
 *     @type int   $default_height
 *     @type int   $default_depth
 *     @type int   $default_width
 *     @type int   $apply_default_dimensions  0|1|2
 *     @type float $min_weight
 *     @type int   $max_height
 *     @type int   $max_depth
 *     @type int   $max_width
 * }
 * @return array|false  { weight, height, depth, width } или false при превышении лимитов.
 */
function yd_calculate_package_dims( $products, $opts ) {
    $coeff      = yd_unit_coefficients();
    $weightC    = $coeff['weight_c'];
    $dimensionC = $coeff['dimension_c'];

    $pack = array( 'weight' => 0, 'height' => 0, 'depth' => 0, 'width' => 0 );

    $defaults = wp_parse_args( $opts, array(
        'default_weight'           => 500,
        'default_height'           => 10,
        'default_depth'            => 10,
        'default_width'            => 10,
        'apply_default_dimensions' => 0,
        'min_weight'               => 0,
        'max_height'               => 0,
        'max_depth'                => 0,
        'max_width'                => 0,
    ) );

    // Режим 2: применить дефолтные габариты ко всей посылке
    if ( (int) $defaults['apply_default_dimensions'] === 2 ) {
        $pack['weight'] = ceil( (float) $defaults['default_weight'] );
        $pack['height'] = (int) $defaults['default_height'];
        $pack['depth']  = (int) $defaults['default_depth'];
        $pack['width']  = (int) $defaults['default_width'];
        return $pack;
    }

    $stackMaxDepth  = 0;
    $stackMaxWidth  = 0;
    $stackSumHeight = 0;

    foreach ( $products as $entry ) {
        $product  = $entry['product'];
        $qty      = (int) $entry['quantity'];
        $varId    = isset( $entry['variation_id'] ) ? $entry['variation_id'] : 0;

        $itemWeight = ( bxbGetWeight( $product, $varId ) ) * $weightC;
        $itemHeight = (int) round( (float) $product->get_height() * $dimensionC );
        $itemDepth  = (int) round( (float) $product->get_length() * $dimensionC );
        $itemWidth  = (int) round( (float) $product->get_width() * $dimensionC );

        // Режим 1: дефолтные габариты для товаров без размеров
        if ( (int) $defaults['apply_default_dimensions'] === 1 ) {
            if ( $itemHeight === 0 || $itemDepth === 0 || $itemWidth === 0 ) {
                $itemHeight = (int) $defaults['default_height'];
                $itemDepth  = (int) $defaults['default_depth'];
                $itemWidth  = (int) $defaults['default_width'];
            }
        }

        $sumDimensions = $itemHeight + $itemDepth + $itemWidth;
        if ( $sumDimensions > 250 ) {
            return false;
        }

        $effectiveWeight = ( $itemWeight <= 0 ) ? ceil( (float) $defaults['default_weight'] ) : ceil( $itemWeight );
        $pack['weight'] += $qty * $effectiveWeight;

        // Стопка: глубина/ширина — макс., высота складывается
        if ( $itemDepth > 0 || $itemWidth > 0 || $itemHeight > 0 ) {
            $stackMaxDepth  = max( $stackMaxDepth, $itemDepth );
            $stackMaxWidth  = max( $stackMaxWidth, $itemWidth );
            $stackSumHeight += $qty * $itemHeight;
        }

        // Проверка лимитов
        if ( ( $defaults['max_height'] > 0 && $itemHeight > $defaults['max_height'] ) ||
             ( $defaults['max_depth'] > 0 && $itemDepth > $defaults['max_depth'] ) ||
             ( $defaults['max_width'] > 0 && $itemWidth > $defaults['max_width'] ) ) {
            return false;
        }
    }

    if ( $stackMaxDepth > 0 || $stackMaxWidth > 0 || $stackSumHeight > 0 ) {
        $pack['depth']  = $stackMaxDepth;
        $pack['width']  = $stackMaxWidth;
        $pack['height'] = $stackSumHeight;
    }

    return $pack;
}

add_action( 'plugins_loaded', 'yd_load_textdomain' );
function yd_load_textdomain()
{
    load_plugin_textdomain( 'yandex-dostavka', false, plugin_basename( dirname( __FILE__ ) ) . '/lang' );
}

// HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action('admin_notices', 'yd_admin_reception_point_notice');

function yd_admin_reception_point_notice()
{
    $need_notice = get_option('yd_reception_point_notice_active');

    if ($need_notice) {
        echo '<div class="error error-warning is-dismissible"><p>Выберите пункты приёма в <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) . '">методах Яндекс Доставки</a></p></div>';
    } else {
        remove_action('admin_notices', 'yd_admin_reception_point_notice');
    }
}

// --- Подменю WooCommerce: Dadata + Тестовый режим ---
add_action( 'admin_menu', 'yd_admin_menus', 99 );
function yd_admin_menus() {
    add_submenu_page(
        'woocommerce',
        'ЯД — Настройки API',
        'ЯД — Настройки API',
        'manage_woocommerce',
        'yandex-dostavka-dadata',
        'yd_dadata_page'
    );
    add_submenu_page(
        'woocommerce',
        'Тестовый режим',
        'Тестовый режим',
        'manage_woocommerce',
        'yandex-dostavka-test-mode',
        'yd_test_mode_page'
    );
}

function yd_dadata_page() {
    if ( isset( $_POST['yd_save_dadata'] ) ) {
        check_admin_referer( 'yd_dadata' );
        $dadata_key = isset( $_POST['yd_dadata_api_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['yd_dadata_api_key'] ) )
            : '';
        $ymaps_key = isset( $_POST['yd_ymaps_api_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['yd_ymaps_api_key'] ) )
            : '';
        update_option( 'yd_dadata_api_key', $dadata_key );
        update_option( 'yd_dadata_token', $dadata_key ? 'Token ' . $dadata_key : '' );
        update_option( 'yd_ymaps_api_key', $ymaps_key );
        echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
    }
    $dadata_key = get_option( 'yd_dadata_api_key', '' );
    $ymaps_key  = get_option( 'yd_ymaps_api_key', '' );
    ?>
    <div class="wrap">
        <h1>Настройки API</h1>
        <form method="post" style="max-width:600px;margin-top:20px;">
            <?php wp_nonce_field( 'yd_dadata' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="yd_dadata_api_key">Dadata API-ключ</label></th>
                    <td>
                        <input type="text" id="yd_dadata_api_key" name="yd_dadata_api_key"
                               value="<?php echo esc_attr( $dadata_key ); ?>"
                               class="regular-text" autocomplete="off"
                               placeholder="Вставьте API-ключ Dadata" />
                        <p class="description">
                            Из <a href="https://dadata.ru/profile/#info" target="_blank" rel="noopener">личного кабинета Dadata</a> &rarr; «API-ключи».
                            Используется для подсказок города и адреса.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="yd_ymaps_api_key">Яндекс Карты API-ключ</label></th>
                    <td>
                        <input type="text" id="yd_ymaps_api_key" name="yd_ymaps_api_key"
                               value="<?php echo esc_attr( $ymaps_key ); ?>"
                               class="regular-text" autocomplete="off"
                               placeholder="Вставьте API-ключ Яндекс Карт" />
                        <p class="description">
                            Из <a href="https://developer.tech.yandex.ru/services" target="_blank" rel="noopener">кабинета разработчика Яндекс</a> &rarr; JavaScript API и HTTP Геокодер.
                            Используется для карты выбора ПВЗ на чекауте.
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="yd_save_dadata" class="button-primary" value="Сохранить" />
            </p>
        </form>
    </div>
    <?php
}

function yd_test_mode_page() {
    $is_on = get_option( 'yd_test_mode', 'no' ) === 'yes';
    $nonce = wp_create_nonce( 'yd_toggle_test_mode' );
    ?>
    <div class="wrap">
        <h1>Тестовый режим магазина</h1>
        <div style="max-width:600px;margin-top:20px;padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
            <p style="font-size:14px;margin-top:0;">
                Когда тестовый режим включён, неавторизованные пользователи видят заглушку
                «Магазин обновляется» на страницах корзины и оформления заказа.
                Авторизованные пользователи работают с магазином как обычно.
            </p>
            <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo $is_on ? '#dc3232' : '#46b450'; ?>;"></span>
                <strong><?php echo $is_on ? 'Тестовый режим ВКЛЮЧЁН' : 'Тестовый режим выключен'; ?></strong>
            </div>
            <p style="margin-top:16px;">
                <button type="button" class="button <?php echo $is_on ? 'button-secondary' : 'button-primary'; ?>"
                        id="yd-toggle-test-mode" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php echo $is_on ? 'Выключить тестовый режим' : 'Включить тестовый режим'; ?>
                </button>
            </p>
        </div>
    </div>
    <script>document.addEventListener('DOMContentLoaded',function(){var btn=document.getElementById('yd-toggle-test-mode');if(btn){btn.addEventListener('click',function(){btn.disabled=true;btn.textContent='...';fetch(ajaxurl+'?action=yd_toggle_test_mode&_wpnonce='+btn.dataset.nonce).then(function(r){return r.json()}).then(function(){location.reload()}).catch(function(){btn.disabled=false;btn.textContent='Ошибка, попробуйте снова'})})}});</script>
    <?php
}

add_action( 'wp_ajax_yd_toggle_test_mode', 'yd_toggle_test_mode_callback' );
function yd_toggle_test_mode_callback() {
    check_ajax_referer( 'yd_toggle_test_mode' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Недостаточно прав', 403 );
    }
    $current = get_option( 'yd_test_mode', 'no' );
    update_option( 'yd_test_mode', $current === 'yes' ? 'no' : 'yes' );
    wp_send_json_success();
}

// --- Тестовый режим: заглушка для неавторизованных на чекауте/корзине ---
add_action( 'template_redirect', 'yd_test_mode_redirect' );
function yd_test_mode_redirect() {
    if ( get_option( 'yd_test_mode', 'no' ) !== 'yes' ) {
        return;
    }
    if ( is_user_logged_in() ) {
        return;
    }
    if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'is_cart' ) ) {
        return;
    }
    if ( is_checkout() || is_cart() ) {
        wp_safe_redirect( home_url() );
        exit;
    }
}

// --- Тестовый режим: скрытие методов доставки для гостей ---
add_filter( 'woocommerce_package_rates', 'yd_test_mode_hide_rates', 100 );
function yd_test_mode_hide_rates( $rates ) {
    if ( get_option( 'yd_test_mode', 'no' ) !== 'yes' ) {
        return $rates;
    }
    if ( is_user_logged_in() ) {
        return $rates;
    }
    foreach ( $rates as $rate_id => $rate ) {
        if ( strpos( $rate_id, 'yd' ) !== false ) {
            unset( $rates[ $rate_id ] );
        }
    }
    return $rates;
}

add_action('plugins_loaded', 'yd_run_updates');
function yd_run_updates()
{
    if (!get_option('yd_deliveries_renamed')) {
        yd_migrate_from_nemirov();
        update_option('yd_deliveries_renamed', 1);
    }

    // Добавить колонки lat/lng/schedule если таблица уже есть но без них
    if ( yd_is_reception_points_table_exist() && ! get_option( 'yd_reception_points_v2' ) ) {
        global $wpdb;
        $t = $wpdb->prefix . 'yd_reception_points';
        $cols = $wpdb->get_col( "DESCRIBE `{$t}`", 0 );
        if ( ! in_array( 'lat', $cols ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN lat DECIMAL(10,7) DEFAULT 0, ADD COLUMN lng DECIMAL(10,7) DEFAULT 0, ADD COLUMN schedule VARCHAR(255) DEFAULT '', ADD INDEX idx_city (city)" );
        }
        update_option( 'yd_reception_points_v2', 1 );
        // Принудительно перезагрузить данные с координатами
        $methods = array( 'yd_self', 'yd_self_after', 'yd_courier', 'yd_courier_after' );
        foreach ( $methods as $mid ) {
            $s = get_option( 'woocommerce_' . $mid . '_settings' );
            if ( is_array( $s ) && ! empty( $s['key'] ) ) {
                yd_add_reception_points( $s['key'] );
                error_log( '[YD] Re-fetched reception points with lat/lng for key from ' . $mid );
                break;
            }
        }
    }

    if (!yd_is_reception_points_table_exist()) {
        yd_create_reception_points_table();
    }

    if (!yd_is_cities_table_exist()) {
        yd_create_cities_table();
    }

    if (!wp_next_scheduled('yd_update_data_event')) {
        error_log('yd_update_data_event активирован');
        wp_schedule_event(time(), 'twicedaily', 'yd_update_data_event');
    }
}

register_activation_hook(__FILE__, 'yd_add_update_data_event');

function yd_add_update_data_event()
{
    if (!wp_next_scheduled('yd_update_data_event')) {
        error_log('yd_update_data_event активирован');
        wp_schedule_event(time(), 'twicedaily', 'yd_update_data_event');
    }
}

register_deactivation_hook(__FILE__,'yd_remove_update_data_event');

function yd_remove_update_data_event()
{
    error_log('yd_update_data_event деактивирован');
    wp_clear_scheduled_hook('yd_update_data_event');
}

add_action('yd_update_data_event', 'yd_run_update_data_event');

function yd_run_update_data_event()
{
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
        return;
    }
    yd_update_api_data();
}

// --- Крон: синхронизация статусов доставки (каждые 2 часа) ---

add_filter( 'cron_schedules', 'yd_add_cron_intervals' );
function yd_add_cron_intervals( $schedules ) {
    $schedules['every_two_hours'] = array(
        'interval' => 2 * HOUR_IN_SECONDS,
        'display'  => 'Каждые 2 часа',
    );
    return $schedules;
}

register_activation_hook( __FILE__, 'yd_add_status_sync_event' );
function yd_add_status_sync_event() {
    if ( ! wp_next_scheduled( 'yd_status_sync_event' ) ) {
        wp_schedule_event( time(), 'every_two_hours', 'yd_status_sync_event' );
    }
}

register_deactivation_hook( __FILE__, 'yd_remove_status_sync_event' );
function yd_remove_status_sync_event() {
    wp_clear_scheduled_hook( 'yd_status_sync_event' );
}

add_action( 'yd_status_sync_event', 'yd_run_status_sync' );
function yd_run_status_sync() {
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
        return;
    }
    yd_sync_order_statuses();
}

/**
 * Синхронизация статусов доставки из API Яндекс Доставки.
 * Проверяет все заказы с трекинг-номером, которые ещё не завершены.
 * Записывает изменения статуса в заметки заказа.
 * Если включена настройка «Авто-завершение при вручении» — переводит заказ в «Выполнен».
 */
function yd_sync_order_statuses() {
    $orders = wc_get_orders( array(
        'limit'      => 50,
        'status'     => array( 'wc-processing', 'wc-on-hold', 'wc-pending' ),
        'meta_query' => array(
            array(
                'key'     => 'yd_tracking_number',
                'compare' => 'EXISTS',
            ),
        ),
        'orderby'    => 'date',
        'order'      => 'DESC',
    ) );

    if ( empty( $orders ) ) {
        return;
    }

    foreach ( $orders as $order ) {
        $trackingNumber = $order->get_meta( 'yd_tracking_number' );
        if ( empty( $trackingNumber ) ) {
            continue;
        }

        $shippingData = bxbGetShippingData( $order );
        if ( ! isset( $shippingData['object'] ) ) {
            continue;
        }

        $key    = $shippingData['object']->get_option( 'key' );
        $apiUrl = $shippingData['object']->get_option( 'api_url' );
        if ( empty( $key ) ) {
            continue;
        }

        $yd_client = new Yandex_Delivery_API( $key );
        $result = $yd_client->get_request_info( $trackingNumber );

        if ( is_wp_error( $result ) ) {
            error_log( 'YandexDelivery status sync error for order #' . $order->get_id() . ': ' . $result->get_error_message() );
            continue;
        }

        if ( ! isset( $result['state'] ) ) {
            continue;
        }

        $statusName = isset( $result['state']['description'] ) ? $result['state']['description'] : ( isset( $result['state']['status'] ) ? $result['state']['status'] : '' );
        $statusDate = isset( $result['state']['updated_ts'] ) ? date( 'd.m.Y H:i', strtotime( $result['state']['updated_ts'] ) ) : current_time( 'd.m.Y H:i' );
        $storedStatus = $order->get_meta( 'yd_last_status' );

        // Если статус изменился — записываем
        if ( $storedStatus !== $statusName ) {
            $order->update_meta_data( 'yd_last_status', $statusName );
            $order->update_meta_data( 'yd_last_status_date', $statusDate );
            $order->add_order_note(
                sprintf( 'Яндекс Доставка: %s (%s)', $statusName, $statusDate ),
                false,
                true
            );

            // Авто-завершение при вручении (если включено в настройках)
            $autoComplete = $shippingData['object']->get_option( 'auto_complete_on_delivery', '0' );
            if ( $autoComplete === '1' && yd_is_delivered_status( $statusName ) ) {
                // update_status() вызывает save() внутри себя
                $order->update_status( 'completed', 'Заказ автоматически завершён: посылка вручена получателю.' );
            } else {
                $order->save();
            }
        }
    }
}

/**
 * Проверяет, означает ли статус доставки что посылка вручена получателю.
 */
function yd_is_delivered_status( $statusName ) {
    $deliveredKeywords = array(
        'Выдано',
        'Вручено',
        'Доставлено',
        'Получено',
        'выдан',
        'вручен',
        'доставлен',
    );
    foreach ( $deliveredKeywords as $keyword ) {
        if ( mb_stripos( $statusName, $keyword ) !== false ) {
            return true;
        }
    }
    return false;
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    $yd_moysklad_file = __DIR__ . '/includes/class-yandex-dostavka-moysklad.php';
    if ( file_exists( $yd_moysklad_file ) ) {
        require_once $yd_moysklad_file;
        YD_MoySklad::init();
    }
    function yd_shipping_method_init()
    {
        class WC_YD_Parent_Method extends WC_Shipping_Method {

            public $instance_id;
            public $self_type;
            public $payment_after;
            public $key;
            public $addcost;
            public $api_url;
            public $widget_url;
            public $ps_on_status;
            public $default_weight;

            public function __construct( $instance_id = 0 )
            {
                parent::__construct();
                $this->instance_id = absint( $instance_id );
                $this->supports    = array(
                    'shipping-zones',
                    'instance-settings'
                );

                $params = array(
                    'title'                               => array(
                        'title'   => 'Название способа доставки',
                        'type'    => 'text',
                        'default' => $this->method_title,
                    ),
                    'key'                                 => array(
                        'title'             => 'API-токен Яндекс Доставки',
                        'description'       => 'Токен из личного кабинета Яндекс Доставки (раздел Интеграция)',
                        'type'              => 'text',
                        'custom_attributes' => array(
                            'required'    => true,
                            'placeholder' => 'Токен из раздела «Интеграция» личного кабинета',
                        )
                    ),
                    'reception_point'                     => array(
                        'title'    => 'Пункт приёма заказов (откуда отправляете)',
                        'type'     => 'text',
                        'desc_tip' => 'Введите город — появится список ПВЗ. Это точка, куда вы привозите посылки для отправки.',
                        'custom_attributes' => array(
                            'required' => true,
                        )
                    ),
                    'default_weight'                      => array(
                        'title'             => 'Вес по умолчанию (г)',
                        'description'       => 'Если у товара не указан вес',
                        'type'              => 'text',
                        'default'           => '500',
                        'custom_attributes' => array(
                            'required' => true
                        )
                    ),
                    'min_weight'                          => array(
                        'title'             => 'Мин. вес (г)',
                        'type'              => 'text',
                        'default'           => '0',
                    ),
                    'max_weight'                          => array(
                        'title'             => 'Макс. вес (г)',
                        'type'              => 'text',
                        'default'           => '31000',
                    ),
                    'default_height' => array(
                        'title'             => 'Высота по умолчанию (см)',
                        'type'              => 'text',
                        'default'           => '',
                    ),
                    'default_depth'  => array(
                        'title'             => 'Глубина по умолчанию (см)',
                        'type'              => 'text',
                        'default'           => '',
                    ),
                    'default_width'  => array(
                        'title'             => 'Ширина по умолчанию (см)',
                        'type'              => 'text',
                        'default'           => '',
                    ),
                    'height'                              => array(
                        'title'   => 'Макс. высота (см)',
                        'type'    => 'text',
                        'default' => '',
                    ),
                    'depth'                               => array(
                        'title'   => 'Макс. глубина (см)',
                        'type'    => 'text',
                        'default' => '',
                    ),
                    'width'                               => array(
                        'title'   => 'Макс. ширина (см)',
                        'type'    => 'text',
                        'default' => '',
                    ),
                    'apply_default_dimensions' => array(
                        'title'    => 'Габариты по умолчанию',
                        'desc_tip' => 'Как применять вес и габариты по умолчанию к отправлению',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'options'  => [
                            0 => 'Не применять',
                            1 => 'К каждому товару без габаритов',
                            2 => 'Ко всему отправлению целиком',
                        ],
                    ),
                    // check_zip removed: option was registered but never read in PHP calculation logic
                    'addcost'                             => array(
                        'title'       => 'Наценка фиксированная (₽)',
                        'description' => 'Сумма в рублях, которая прибавляется к цене из API Яндекса. 0 = без наценки.',
                        'desc_tip'    => true,
                        'type'        => 'decimal',
                        'default'     => '0',
                    ),
                    'markup_percent'                      => array(
                        'title'       => 'Наценка процентная (%)',
                        'description' => 'Процент наценки поверх (цена API + фиксированная наценка). 0 = без наценки.',
                        'desc_tip'    => true,
                        'type'        => 'decimal',
                        'default'     => '0',
                    ),
                    'fixed_cost'                          => array(
                        'title'       => 'Фиксированная стоимость (₽)',
                        'description' => 'Фоллбек если API недоступен. 0 = бесплатно при ошибке API.',
                        'desc_tip'    => true,
                        'type'        => 'decimal',
                        'default'     => '350',
                    ),
                    'surch'                               => array(
                        'title'    => 'Виджет: показывать стоимость',
                        'desc_tip' => 'Управляет отображением стоимости в виджете ПВЗ (data-surch). На серверный расчёт не влияет.',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'options'  => [
                            1 => 'Нет',
                            0 => 'Да',
                        ]
                    ),
                    'enable_for_selected_payment_methods' => array(
                        'title'             => 'Только для способов оплаты',
                        'type'              => 'multiselect',
                        'class'             => 'wc-enhanced-select',
                        'css'               => 'width: 400px;',
                        'default'           => '',
                        'description'       => 'Доставка доступна только при выбранных способах оплаты',
                        'options'           => $this->get_available_payment_methods(),
                        'desc_tip'          => true,
                        'custom_attributes' => array(
                            'data-placeholder' => 'Выберите способы оплаты'
                        )
                    ),
                    'bxbbutton'                           => array(
                        'title'    => 'Кнопка выбора ПВЗ',
                        'desc_tip' => 'Показывать кнопку открытия виджета (иначе — ссылка)',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'options'  => [
                            0 => 'Нет',
                            1 => 'Да',
                        ]
                    ),
                    'parselcreate_on_status'              => array(
                        'title'    => 'Авто-отправка при статусе',
                        'desc_tip' => 'Заказ отправится автоматически при наступлении этого статуса',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'default'  => 'none',
                        'options'  => [ 'none' => 'Не использовать' ] + wc_get_order_statuses()
                    ),
                    'order_status_send'                   => array(
                        'title'    => 'Статус после отправки',
                        'desc_tip' => 'Какой статус установить заказу после отправки',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'default'  => 'none',
                        'options'  => [ 'none' => 'Не использовать' ] + wc_get_order_statuses()
                    ),
                    'autoact'                             => array(
                        'title'    => 'Авто-акт после выгрузки',
                        'desc_tip' => 'Формировать акт автоматически после выгрузки заказа',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'options'  => [
                            0 => 'Нет',
                            1 => 'Да',
                        ]
                    ),
                    'auto_complete_on_delivery'            => array(
                        'title'    => 'Авто-завершение при вручении',
                        'desc_tip' => 'Автоматически переводить заказ в статус «Выполнен» когда посылка вручена получателю. Статус проверяется каждые 2 часа.',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'default'  => '0',
                        'options'  => [
                            '1' => 'Да',
                            '0' => 'Нет',
                        ]
                    ),
                    'order_prefix'                        => array(
                        'title'    => 'Префикс заказа',
                        'desc_tip' => 'Добавляется к номеру заказа при выгрузке',
                        'type'     => 'text',
                        'default'  => 'wp'
                    ),
                );

                if ( is_array( $this->instance_form_fields ) ) {
                    $this->instance_form_fields = array_merge( $this->instance_form_fields, $params );
                } else {
                    $this->instance_form_fields = $params;
                }

                $this->key          = $this->get_option( 'key' );
                $this->title        = $this->get_option( 'title' );
                $this->addcost      = $this->get_option( 'addcost' );
                $this->api_url      = $this->get_option( 'api_url' );
                $this->widget_url   = $this->get_option( 'widget_url' );
                $this->ps_on_status = $this->get_option( 'parselcreate_on_status' );

                add_action( 'woocommerce_update_options_shipping_' . $this->id, array(
                    $this,
                    'process_admin_options'
                ) );
            }

            public function process_admin_options() {
                $post_data = $this->get_post_data();
                $fields = $this->get_form_fields();
                $key = isset( $fields['key'] ) ? $this->get_field_value('key', $fields['key'], $post_data) : '';
                $api_url = isset( $fields['api_url'] ) ? $this->get_field_value('api_url', $fields['api_url'], $post_data) : '';

                // Сначала проверяем токен — если невалиден, всё равно сохраняем остальные настройки
                if ( ! empty( $key ) ) {
                    $yd_client = new Yandex_Delivery_API( $key );
                    if ( $yd_client->validate_token() ) {
                        if ( yd_is_reception_points_table_empty() ) {
                            yd_add_reception_points( $key );
                        }
                    } else {
                        WC_Admin_Settings::add_error( 'Токен невалиден: ' . ( $yd_client->get_last_error() ?: 'неизвестная ошибка' ) );
                    }
                }

                // Всегда сохраняем — даже если пункт приёма пуст (можно заполнить позже)
                parent::process_admin_options();

                $reception_point = $this->get_option('reception_point');
                if (empty($reception_point)) {
                    update_option('yd_reception_point_notice_active', 1);
                } else {
                    update_option('yd_reception_point_notice_active', 0);
                }
            }

            private function is_accessing_settings()
            {
                if ( is_admin() ) {
                    // phpcs:disable WordPress.Security.NonceVerification.Recommended
                    if ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) ) {
                        return false;
                    }
                    if ( ! isset( $_REQUEST['tab'] ) || 'shipping' !== sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) ) ) {
                        return false;
                    }
                    if ( ! isset( $_REQUEST['instance_id'] ) ) {
                        return false;
                    }
                    // phpcs:enable WordPress.Security.NonceVerification.Recommended

                    return true;
                }

                return false;
            }

            private function get_option_from_db( $args )
            {
                global $wpdb;
                $query = "SELECT * FROM {$wpdb->prefix}options WHERE option_name = %s LIMIT 1";

                return $wpdb->get_results( $wpdb->prepare( $query, $args ) );
            }

            private function get_payment_method_title( $payment_method_id )
            {
                $payment_method_title_result = $this->get_option_from_db( 'woocommerce_' . $payment_method_id . '_settings' );

                if ( ! isset( $payment_method_title_result[0] ) ) {
                    return '';
                }

                $payment_method_values = maybe_unserialize( $payment_method_title_result[0]->option_value );

                if ( ! is_array( $payment_method_values ) ) {
                    return '';
                }

                if ( ! isset( $payment_method_values['enabled'], $payment_method_values['title'] ) ) {
                    return '';
                }

                if ( $payment_method_values['enabled'] !== 'yes' ) {
                    return '';
                }

                return $payment_method_values['title'];
            }

            private function get_available_payment_methods()
            {
                if ( ! $this->is_accessing_settings() ) {
                    return [];
                }

                $gateways = WC()->payment_gateways();
                if ( ! $gateways ) {
                    return [];
                }

                $methods = [];
                foreach ( $gateways->payment_gateways() as $gateway ) {
                    if ( $gateway->enabled === 'yes' ) {
                        $methods[ $gateway->id ] = $gateway->get_title();
                    }
                }

                return $methods;
            }

            private function check_payment_method_for_calc()
            {
                $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
                $option                = $this->get_option( 'enable_for_selected_payment_methods' );

                if ( $chosen_payment_method === '' || $option === '' || ( empty( $option ) && ! is_array( $option ) ) ) {
                    return true;
                }

                if ( is_array( $option ) ) {
                    return empty( $option ) || in_array( $chosen_payment_method, $option, true );
                }

                return $chosen_payment_method === $option;
            }

            private function extract_city_and_state( $location_string )
            {
                $parts = explode( ',', $location_string );
                $city  = isset( $parts[0] ) ? trim( preg_replace( '/^(город|г\.?|село|деревня|д\.?)\s+/iu', '', $parts[0] ) ) : '';
                $state = '';
                if ( isset( $parts[1] ) && isset( $parts[2] ) ) {
                    $state = trim( $parts[2] );
                } elseif ( isset( $parts[1] ) ) {
                    $state = trim( $parts[1] );
                }

                $state = preg_replace( '/\bрайон\b/iu', '', $state );
                $state = trim( str_ireplace(
                    [
                        'область',
                        'обл',
                        'край',
                        'республика',
                        'респ'
                    ], '', $state ) );

                return [ 'city' => $city, 'state' => $state ];
            }

            private function check_reception_point_is_selected()
            {
                if (!$this->get_option('reception_point')) {
                    update_option('yd_reception_point_notice_active', 1);
                } else {
                    update_option('yd_reception_point_notice_active', 0);
                }
            }

            final public function calculate_shipping( $package = array() )
            {
                $yd_client = new Yandex_Delivery_API( $this->key );

                if ( yd_is_reception_points_table_empty() ) {
                    yd_add_reception_points( $this->key );
                }

                $this->check_reception_point_is_selected();

                if ( ! $this->check_payment_method_for_calc() ) {
                    return false;
                }

                // PHP 8.1 compat: cast to string before trim to avoid deprecation notice on null
                if ( ( isset( $package['destination']['city'] ) && empty( trim( (string) $package['destination']['city'] ) ) ) || current_action() === 'woocommerce_add_to_cart' ) {
                    return false;
                }

                $defaultWeight          = (float) $this->get_option( 'default_weight' );
                $defaultHeight          = (int) $this->get_option( 'default_height' );
                $defaultDepth           = (int) $this->get_option( 'default_depth' );
                $defaultWidth           = (int) $this->get_option( 'default_width' );
                $applyDefaultDimensions = (int) $this->get_option( 'apply_default_dimensions' );
                $minWeight              = (float) $this->get_option( 'min_weight' );
                $maxWeight              = (float) $this->get_option( 'max_weight' );
                $maxHeight              = (int) $this->get_option( 'height' );
                $maxDepth               = (int) $this->get_option( 'depth' );
                $maxWidth               = (int) $this->get_option( 'width' );
                // P0 Fix: единая функция габаритов yd_calculate_package_dims() — и корзина, и заказ
                $cartProducts = array();
                foreach ( $package['contents'] as $cartProduct ) {
                    $product = isset( $cartProduct['data'] ) ? $cartProduct['data'] : wc_get_product( $cartProduct['product_id'] );
                    if ( ! $product || $product->is_virtual() || $product->is_downloadable() ) {
                        continue;
                    }
                    $cartProducts[] = array(
                        'product'      => $product,
                        'quantity'     => (int) $cartProduct['quantity'],
                        'variation_id' => isset( $cartProduct['variation_id'] ) ? $cartProduct['variation_id'] : 0,
                    );
                }
                $fullPackage = yd_calculate_package_dims( $cartProducts, array(
                    'default_weight'           => $defaultWeight,
                    'default_height'           => $defaultHeight,
                    'default_depth'            => $defaultDepth,
                    'default_width'            => $defaultWidth,
                    'apply_default_dimensions' => $applyDefaultDimensions,
                    'min_weight'               => $minWeight,
                    'max_height'               => $maxHeight,
                    'max_depth'                => $maxDepth,
                    'max_width'                => $maxWidth,
                ) );
                if ( $fullPackage === false ) {
                    return false;
                }

                // P2 Fix: логируем предупреждение при выходе за пределы веса (вместо молчаливого пропуска)
                if ( $fullPackage['weight'] < $minWeight ) {
                    error_log( sprintf( '[YD] Package weight %dg is below min_weight %dg for method %s — rate hidden', $fullPackage['weight'], $minWeight, $this->id ) );
                }
                if ( $fullPackage['weight'] > $maxWeight ) {
                    error_log( sprintf( '[YD] Package weight %dg exceeds max_weight %dg for method %s — rate hidden', $fullPackage['weight'], $maxWeight, $this->id ) );
                }

                if ( $minWeight <= $fullPackage['weight'] && $maxWeight >= $fullPackage['weight'] ) {
                    $city  = $package['destination']['city'];
                    $state = $package['destination']['state'];

                    if ( empty( $package['destination']['state'] ) ) {
                        $location_data = $this->extract_city_and_state( $package['destination']['city'] );
                        $city          = $location_data['city'];
                        $state         = $location_data['state'];
                    }

                    // Определяем адрес отправления из пункта приёма
                    $receptionPointName = $this->get_option( 'reception_point' );
                    $sourceAddress = $receptionPointName ? $receptionPointName : get_option( 'woocommerce_store_city', '' );

                    // platform_station_id склада — без него API считает по общему тарифу
                    $sourceStationId = '';
                    if ( $receptionPointName ) {
                        $sourceStationId = getReceptionPointCodeByName( $receptionPointName );
                        // P2 Fix: предупреждение если station_id не найден для пункта приёма
                        if ( empty( $sourceStationId ) ) {
                            error_log( '[YD] WARNING: platform_station_id not found for reception point "' . $receptionPointName . '". API will use generic tariff.' );
                        }
                    }

                    // Адрес назначения: для ПВЗ — точный адрес пункта (из cookie),
                    // для курьера — город + индекс из формы
                    $destinationAddress = trim( $state . ' ' . $city );
                    $destinationStationId = '';

                    if ( $this->self_type ) {
                        // ПВЗ ещё не выбран — не вызываем API, показываем заглушку
                        if ( empty( $_COOKIE['yd_pvz_code'] ) ) {
                            $this->add_rate( array(
                                'id'    => $this->get_rate_id(),
                                'label' => $this->title . ': выберите пункт выдачи',
                                'cost'  => 0,
                            ) );
                            return false;
                        }

                        // Адрес назначения для ПВЗ: берём из cookie, если он есть.
                        if ( ! empty( $_COOKIE['yd_pvz_address'] ) ) {
                            $pvzAddr = sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_address'] ) );
                            if ( $pvzAddr ) {
                                $destinationAddress = $pvzAddr;
                            }
                        }

                        // platform_station_id ПВЗ
                        $destinationStationId = sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) );
                    } elseif ( isset( $package['destination']['postcode'] ) && ! empty( $package['destination']['postcode'] ) && $package['destination']['postcode'] !== '111111' ) {
                        // Для курьера: добавляем реальный индекс (не фейковый 111111)
                        $destinationAddress .= ', ' . $package['destination']['postcode'];
                    }

                    // Тариф: self_pickup для ПВЗ, time_interval для курьера
                    $tariff = $this->self_type ? 'self_pickup' : 'time_interval';

                    // P0 Fix: единая формула оценочной стоимости (копейки)
                    $qualifiedCartItems = array();
                    foreach ( WC()->cart->get_cart() as $ci ) {
                        $p = $ci['data'];
                        if ( ! $p->is_virtual() && ! $p->is_downloadable() ) {
                            $qualifiedCartItems[] = $ci;
                        }
                    }
                    $assessedPrice = yd_assessed_price_minor_units( $qualifiedCartItems, 'cart' );

                    $dimensions = array(
                        'length' => max( 1, (int) $fullPackage['depth'] ),
                        'width'  => max( 1, (int) $fullPackage['width'] ),
                        'height' => max( 1, (int) $fullPackage['height'] ),
                    );

                    // Кэш в рамках одного запроса — yd_self и yd_self_after
                    // не дублируют API-вызов если параметры одинаковые
                    $cacheKey = md5( wp_json_encode( array(
                        $sourceStationId ?: $sourceAddress,
                        $destinationStationId ?: $destinationAddress,
                        $tariff,
                        (int) $fullPackage['weight'],
                        $assessedPrice,
                        $dimensions,
                    ) ) );

                    if ( ! isset( $GLOBALS['yd_pricing_cache'][ $cacheKey ] ) ) {
                        // Debug (only with WP_DEBUG, addresses masked for PII)
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[YD] === CALC REQUEST (' . $this->id . ') ===' );
                            error_log( '[YD] source_station=' . ( $sourceStationId ?: 'N/A' ) );
                            error_log( '[YD] dest_station=' . ( $destinationStationId ?: 'N/A' ) );
                            error_log( '[YD] tariff=' . $tariff . ', weight=' . (int) $fullPackage['weight'] . 'g' );
                            error_log( '[YD] dims: L=' . $dimensions['length'] . ' W=' . $dimensions['width'] . ' H=' . $dimensions['height'] . ' cm' );
                            error_log( '[YD] assessed_price=' . $assessedPrice . ' kopecks (' . round( $assessedPrice / 100, 2 ) . ' RUB)' );
                        }

                        $GLOBALS['yd_pricing_cache'][ $cacheKey ] = $yd_client->calculate_price(
                            $sourceAddress,
                            $destinationAddress,
                            $tariff,
                            (int) $fullPackage['weight'],
                            $assessedPrice,
                            $dimensions,
                            $sourceStationId,
                            $destinationStationId
                        );
                    } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[YD] === CALC CACHED (' . $this->id . ') === reusing result from previous method' );
                    }

                    $result = $GLOBALS['yd_pricing_cache'][ $cacheKey ];

                    $costReceived = false;

                    if ( ! is_wp_error( $result ) && isset( $result['pricing_total'] ) ) {
                        $costReceived = (float) $result['pricing_total'];
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[YD] pricing_total=' . ( $result['pricing_total'] ?? 'N/A' ) );
                        }
                    } else {
                        $fixedCost = (float) $this->get_option( 'fixed_cost', 350 );
                        $costReceived = $fixedCost;
                        // Fallback всегда логируем — это ошибка, не debug
                        $apiErr = is_wp_error( $result ) ? $result->get_error_message() : 'pricing_total not found';
                        error_log( '[YD] fallback to fixed_cost=' . $fixedCost . ': ' . $apiErr );
                    }

                    // Срок доставки
                    $deliveryPeriod = '';
                    if ( ! is_wp_error( $result ) && isset( $result['delivery_date'] ) ) {
                        $days = isset( $result['delivery_date']['max_days'] ) ? (int) $result['delivery_date']['max_days'] : 0;
                        if ( $days > 0 ) {
                            if ( get_bloginfo( 'language' ) === 'ru-RU' ) {
                                $deliveryPeriod = ' (' . $days . ' ' . yd_plural_days( $days, 'рабочий день', 'рабочих дня', 'рабочих дней' ) . ') ';
                            } else {
                                $deliveryPeriod = ' (' . $days . ' ' . ( $days === 1 ? 'day' : 'days' ) . ')';
                            }
                        }
                    }

                    // Наценки: фиксированная + процентная
                    $finalCost = $costReceived + (float) $this->addcost;
                    $markupPercent = (float) $this->get_option( 'markup_percent', 0 );
                    if ( $markupPercent > 0 ) {
                        $finalCost = $finalCost * ( 1 + $markupPercent / 100 );
                    }
                    $finalCost = round( $finalCost, 2 );

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[YD] pricing: base=' . $costReceived . ' +add=' . $this->addcost . ' +markup=' . $markupPercent . '% = ' . $finalCost );
                    }

                    $this->add_rate( [
                        'id'    => $this->get_rate_id(),
                        'label' => ( $this->title . $deliveryPeriod ),
                        'cost'  => $finalCost,
                    ] );
                }

                return false;
            }
        }

        class WC_YD_Self_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_self';
                $this->method_title         = 'Яндекс Доставка — ПВЗ';
                $this->instance_form_fields = array();
                $this->self_type            = true;
                $this->payment_after        = false;
                parent::__construct( $instance_id );
                $this->default_weight = $this->get_option( 'default_weight' );
                $this->key            = $this->get_option( 'key' );
            }
        }

        class WC_YD_SelfAfter_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_self_after';
                $this->method_title         = 'Яндекс Доставка — ПВЗ (наложенный платёж)';
                $this->instance_form_fields = array();
                $this->self_type            = true;
                $this->payment_after        = true;
                parent::__construct( $instance_id );
                $this->default_weight = $this->get_option( 'default_weight' );
                $this->key            = $this->get_option( 'key' );
            }
        }

        class WC_YD_Courier_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_courier';
                $this->method_title         = 'Яндекс Доставка — Курьер';
                $this->instance_form_fields = array();
                $this->self_type            = false;
                $this->payment_after        = false;
                parent::__construct( $instance_id );
                $this->key = $this->get_option( 'key' );
            }
        }

        class WC_YD_CourierAfter_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_courier_after';
                $this->method_title         = 'Яндекс Доставка — Курьер (наложенный платёж)';
                $this->instance_form_fields = array();
                $this->self_type            = false;
                $this->payment_after        = true;
                parent::__construct( $instance_id );
                $this->key = $this->get_option( 'key' );
            }
        }
    }

    /**
     * Склонение слова «день» для русского языка.
     */
    function yd_plural_days( $n, $form1, $form2, $form5 ) {
        $n = abs( (int) $n ) % 100;
        $n1 = $n % 10;
        if ( $n > 10 && $n < 20 ) {
            return $form5;
        }
        if ( $n1 > 1 && $n1 < 5 ) {
            return $form2;
        }
        if ( $n1 === 1 ) {
            return $form1;
        }
        return $form5;
    }

    function bxbGetWeight( $product, $id = 0 )
    {
        if ( ! $product ) {
            return 0;
        }

        if ( $product->is_type( 'variable' ) && $id > 0 ) {
            $variation = wc_get_product( $id );
            if ( $variation ) {
                $weight = (float) $variation->get_weight();
                if ( $weight > 0 ) {
                    return $weight;
                }
            }
        }

        return (float) $product->get_weight();
    }

    // bxbGetUrl() removed — dead code, never called

    add_action( 'woocommerce_shipping_init', 'yd_shipping_method_init' );

    function yd_shipping_method( $methods )
    {
        $methods['yd_self']          = 'WC_YD_Self_Method';
        $methods['yd_courier']       = 'WC_YD_Courier_Method';
        $methods['yd_self_after']    = 'WC_YD_SelfAfter_Method';
        $methods['yd_courier_after'] = 'WC_YD_CourierAfter_Method';

        return $methods;
    }

    add_filter( 'woocommerce_shipping_methods', 'yd_shipping_method' );

    /**
     * Включаем PVZ code в хэш пакета доставки, чтобы WooCommerce
     * инвалидировал кэш shipping rates при смене выбранного ПВЗ.
     */
    add_filter( 'woocommerce_cart_shipping_packages', function( $packages ) {
        $pvz_code = isset( $_COOKIE['yd_pvz_code'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) : '';
        foreach ( $packages as &$package ) {
            $package['yd_pvz_code'] = $pvz_code;
        }
        return $packages;
    } );

    /**
     * Для ПВЗ-методов с cost=0 (ПВЗ ещё не выбран) — убираем цену из label,
     * чтобы не показывать «Бесплатно» или «0 ₽».
     */
    add_filter( 'woocommerce_cart_shipping_method_full_label', function( $label, $method ) {
        if ( strpos( $method->get_id(), 'yd_self' ) !== false && (float) $method->get_cost() == 0 ) {
            return $method->get_label();
        }
        return $label;
    }, 10, 2 );

    function yd_add_meta_tracking_code_box( $post_type, $post )
    {
        if ( strpos( $post_type, 'shop_order' ) === false && strpos( $post_type, 'wc-order' ) === false ) {
            return;
        }

        $order_id = $post instanceof WC_Order ? $post->get_id() : ( isset( $post->ID ) ? $post->ID : 0 );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $shippingData = bxbGetShippingData( $order );
        if ( empty( $shippingData ) || strpos( $shippingData['method_id'], 'yd' ) === false ) {
            return;
        }

        add_meta_box(
            'yd_meta_tracking_code',
            __( $shippingData['title'], 'yandex-dostavka' ),
            'yd_tracking_code',
            $post_type,
            'side',
            'core'
        );
    }

    add_action( 'add_meta_boxes', 'yd_add_meta_tracking_code_box', 10, 2 );

    function action_woocommerce_checkout_update_order_review( $postedData )
    {
        $packages = WC()->cart->get_shipping_packages();
        foreach ( $packages as $packageKey => $package ) {
            $sessionKey = 'shipping_for_package_' . $packageKey;
            WC()->session->__unset( $sessionKey );
        }
    }

    add_action( 'woocommerce_checkout_update_order_review', 'action_woocommerce_checkout_update_order_review', 10, 1 );

    // isCodAvailableForCountry() removed — dead code, never called
    // validateShippingZone() removed — dead code, never called

    function bxbGetLastStatusInOrder( $data )
    {
        $yd_client = new Yandex_Delivery_API( $data['key'] );
        $history = $yd_client->get_request_history( $data['track'] );

        if ( ! is_wp_error( $history ) && ! empty( $history['history'] ) ) {
            $html = '<div><ul class="order_notes" style="max-height: 300px; overflow-y: auto;">';
            $items = array_reverse( $history['history'] );
            foreach ( $items as $idx => $status ) {
                $statusName = isset( $status['description'] ) ? $status['description'] : ( isset( $status['status'] ) ? $status['status'] : '' );
                $statusDate = isset( $status['timestamp'] ) ? date( 'd.m.Y H:i', strtotime( $status['timestamp'] ) ) : '';
                $noteClass = ( $idx === 0 ) ? 'note system-note' : 'note';
                $html .= '<li class="' . $noteClass . '">
                            <div class="note_content">
                                <p>' . esc_html( $statusName ) . '</p>
                            </div>
                            <p class="meta"><abbr class="exact-date">' . esc_html( $statusDate ) . '</abbr></p>
                          </li>';
            }
            $html .= '</ul></div>';
            return $html;
        }

        // Фоллбэк: попытаемся получить текущий статус из get_request_info
        $info = $yd_client->get_request_info( $data['track'] );
        if ( ! is_wp_error( $info ) && isset( $info['state'] ) ) {
            $statusName = isset( $info['state']['description'] ) ? $info['state']['description'] : ( isset( $info['state']['status'] ) ? $info['state']['status'] : '' );
            $statusDate = isset( $info['state']['updated_ts'] ) ? date( 'd.m.Y H:i', strtotime( $info['state']['updated_ts'] ) ) : '';
            return '<div><ul class="order_notes">
                        <li class="note system-note">
                            <div class="note_content"><p>' . esc_html( $statusName ) . '</p></div>
                            <p class="meta"><abbr class="exact-date">' . esc_html( $statusDate ) . '</abbr></p>
                        </li>
                    </ul></div>';
        }

        return '<div>
                    <ul class="order_notes">
                        <li class="note">
                            <div class="note_content">
                                <p>На данный момент статусы по заказу ещё не доступны.</p>
                            </div>
                        </li>
                    </ul>
               </div>';
    }

    function yd_tracking_code( $post )
    {
        $order        = wc_get_order( $post );
        if ( ! $order ) {
            return;
        }
        $order_id     = $order->get_id();
        $shippingData = bxbGetShippingData( $order );

        if ( isset( $shippingData['object'] ) ) {
            $trackingNumber   = $order->get_meta( 'yd_tracking_number' );
            $labelLink        = $order->get_meta( 'yd_link' );
            $actLink          = $order->get_meta( 'yd_act_link' );
            $errorText        = $order->get_meta( 'yd_error' );
            $pvzCode          = $order->get_meta( 'yd_code' );
            $yd_address  = $order->get_meta( 'yd_address' );
            $key              = $shippingData['object']->get_option( 'key' );

            if (!$receptionPoint = getReceptionPointCodeByName( $shippingData['object']->get_option( 'reception_point' )) ) {
                $receptionPoint = '';
            }

            $orderData = [
                'track'  => $trackingNumber,
                'act'    => $actLink,
                'key'    => $key,
            ];

            if ( isset( $errorText ) && empty( $trackingNumber ) && $errorText !== '' ) {
                echo '<p><b><u>Возникла ошибка</u></b>: ' . wp_kses_post( $errorText ) . '</p>';
                echo '<p><input type="submit" class="add_note button" name="yd_create_parsel" value="Попробовать снова"></p>';

                if ( $shippingData['object']->self_type ) {
                    echo '<p>Код пункта выдачи: <a href="#" data-yandex-dostavka-reception-point="' . esc_attr( $receptionPoint ) . '" data-yandex-dostavka-widget-key="" data-id="' . esc_attr(
                            $order_id
                        ) . '" data-yandex-dostavka-open="true" data-yandex-dostavka-city="' . esc_attr(
                             $order->get_shipping_city()
                         ) . '">' . esc_html(
                             $pvzCode
                         ) . '</a></p>';
                    echo '<p>Адрес пункта выдачи: ' . esc_html( $yd_address ) . '</p>';
                }
            } elseif ( isset( $trackingNumber ) && $trackingNumber !== '' ) {
                echo '<p><span style="display: inline-block;">Номер отправления:</span>';
                echo '<span style="margin-left: 10px"><b>' . esc_html( $trackingNumber ) . '</b></span>';
                echo '<p><a class="button" href="' . esc_url( $labelLink ) . '" target="_blank">Скачать этикетку</a></p>';

                if ( isset( $actLink ) && $actLink !== '' ) {
                    echo '<p><a class="button" href="' . esc_url( $actLink ) . '" target="_blank">Скачать акт</a></p>';
                }

                if ( empty( $actLink ) ) {
                    echo '<p><input type="submit" class="add_note button" name="yd_create_act" value="Сформировать акт"></p>';
                }

                echo '<p>Текущий статус заказа в Яндекс Доставке:</p>';
                echo bxbGetLastStatusInOrder( $orderData );
            } else {
                if ( $shippingData['object']->self_type ) {
                    if ( $pvzCode === '' ) {
                        echo '<p><a href="#" data-id="' . esc_attr(
                                $order_id
                            ) . '" data-yandex-dostavka-open="true" data-yandex-dostavka-reception-point="' . esc_attr( $receptionPoint ) . '" data-yandex-dostavka-widget-key="" data-yandex-dostavka-city="' . esc_attr(
                                 $order->get_shipping_state()
                             ) . ' ' . esc_attr( $order->get_shipping_city() ) . '">Выберите ПВЗ</a></p>';

                        return;
                    }

                    echo '<p>Код пункта выдачи: <a href="#" data-yandex-dostavka-reception-point="' . esc_attr( $receptionPoint ) . '" data-yandex-dostavka-widget-key="" data-id="' . esc_attr(
                            $order_id
                        ) . '" data-yandex-dostavka-open="true" data-yandex-dostavka-city="' . esc_attr(
                             $order->get_shipping_city()
                         ) . '">' . esc_html(
                             $pvzCode
                         ) . '</a></p>';
                    echo '<p>Адрес пункта выдачи: ' . esc_html( $yd_address ) . '</p>';
                }
                echo '<p>После нажатия кнопки заказ будет создан в системе Яндекс Доставки.</p>';
                echo '<p><input type="submit" class="add_note button" name="yd_create_parsel" value="Отправить заказ в систему"></p>';
            }
        }
    }

    function yd_meta_tracking_code( $postId )
    {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }
        if ( isset( $_POST['yd_create_parsel'] ) ) {
            if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $postId ) ) {
                yd_get_tracking_code( $postId );
            }
        }
        if ( isset( $_POST['yd_create_act'] ) ) {
            if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $postId ) ) {
                bxbCreateAct( $postId );
            }
        }
    }

    add_action( 'woocommerce_process_shop_order_meta', 'yd_meta_tracking_code', 0, 2 );

    function bxbCreateAct( $postId )
    {
        $order = wc_get_order( $postId );
        if ( ! $order ) {
            return;
        }
        $shippingData = bxbGetShippingData( $order );

        if ( isset( $shippingData['object'] ) ) {
            $trackingNumber = $order->get_meta( 'yd_tracking_number' );
            $key = $shippingData['object']->get_option( 'key' );

            $yd_client = new Yandex_Delivery_API( $key );
            $result = $yd_client->generate_labels( array( $trackingNumber ) );

            if ( is_wp_error( $result ) ) {
                $order->update_meta_data( 'yd_error', $result->get_error_message() );
                $order->save();
                return;
            }

            if ( isset( $result['label_url'] ) ) {
                $order->update_meta_data( 'yd_act_link', $result['label_url'] );
                $order->delete_meta_data( 'yd_error' );
                $order->save();
            } elseif ( isset( $result['url'] ) ) {
                $order->update_meta_data( 'yd_act_link', $result['url'] );
                $order->delete_meta_data( 'yd_error' );
                $order->save();
            } else {
                // Попробуем generate_act как альтернативу
                $act_result = $yd_client->generate_act( array( 'request_ids' => array( $trackingNumber ) ) );
                if ( ! is_wp_error( $act_result ) && isset( $act_result['url'] ) ) {
                    $order->update_meta_data( 'yd_act_link', $act_result['url'] );
                    $order->delete_meta_data( 'yd_error' );
                    $order->save();
                } else {
                    $err = is_wp_error( $act_result ) ? $act_result->get_error_message() : 'Не удалось сформировать акт';
                    $order->update_meta_data( 'yd_error', $err );
                    $order->save();
                }
            }
        }
    }

    function yd_get_tracking_code( $postId )
    {
        $order = wc_get_order( $postId );
        if ( ! $order ) {
            return;
        }
        $orderId = $order->get_id();

        $shippingData = bxbGetShippingData( $order );

        if ( isset( $shippingData['object'] ) ) {
            $key = $shippingData['object']->get_option( 'key' );
            $yd_client = new Yandex_Delivery_API( $key );

            // Данные получателя
            $customerName  = $order->get_formatted_shipping_full_name();
            $customerPhone = $order->get_meta( '_shipping_phone' );
            $customerEmail = $order->get_meta( '_shipping_email' );

            if ( trim( $customerName ) === '' ) {
                $customerName = $order->get_formatted_billing_full_name();
            }
            if ( trim( $customerPhone ) === '' ) {
                $customerPhone = $order->get_billing_phone();
            }
            if ( trim( $customerEmail ) === '' ) {
                $customerEmail = $order->get_billing_email();
            }

            // Собираем товары и вычисляем вес/габариты
            $orderItems   = $order->get_items( 'line_item' );
            $declaredCost = 0;
            $items_list   = array();

            $defaultWeight          = (float) $shippingData['object']->get_option( 'default_weight' );
            $defaultHeight          = (int) $shippingData['object']->get_option( 'default_height' );
            $defaultDepth           = (int) $shippingData['object']->get_option( 'default_depth' );
            $defaultWidth           = (int) $shippingData['object']->get_option( 'default_width' );
            $applyDefaultDimensions = (int) $shippingData['object']->get_option( 'apply_default_dimensions' );
            $minWeight              = (float) $shippingData['object']->get_option( 'min_weight' );

            foreach ( $orderItems as $orderItem ) {
                $product = $orderItem->get_product();

                if ( ! $product || $product->is_virtual() || $product->is_downloadable() ) {
                    continue;
                }

                // declaredCost больше не используется для assessed_price, но нужен для payment_sum
                $declaredCost += (float) $orderItem->get_total() + (float) $orderItem->get_total_tax();

                $sku = is_callable( array( $product, 'get_sku' ) ) ? $product->get_sku() : '';
                $id  = (string) ( $sku !== '' ? $sku : $orderItem['product_id'] );

                $items_list[] = array(
                    'article'    => $id,
                    'name'       => $orderItem['name'],
                    // Fix 2.2: include tax to match total_assessed_price basis
                    'price'      => round( ( (float) $orderItem->get_total() + (float) $orderItem->get_total_tax() ) / $orderItem->get_quantity(), 2 ),
                    'count'      => $orderItem->get_quantity(),
                    'weight'     => 0, // будет заполнено ниже если нужно
                );

            }

            // P0 Fix: единая функция габаритов (стопка), как в calculate_shipping
            $packageProducts = array();
            foreach ( $orderItems as $orderItem ) {
                $product = $orderItem->get_product();
                if ( ! $product || $product->is_virtual() || $product->is_downloadable() ) {
                    continue;
                }
                $packageProducts[] = array(
                    'product'      => $product,
                    'quantity'     => $orderItem->get_quantity(),
                    'variation_id' => $orderItem->get_variation_id(),
                );
            }
            $fullPackage = yd_calculate_package_dims( $packageProducts, array(
                'default_weight'           => $defaultWeight,
                'default_height'           => $defaultHeight,
                'default_depth'            => $defaultDepth,
                'default_width'            => $defaultWidth,
                'apply_default_dimensions' => $applyDefaultDimensions,
                'min_weight'               => $minWeight,
            ) );
            if ( $fullPackage === false ) {
                $order->update_meta_data( 'yd_error', 'Превышены лимиты габаритов посылки' );
                $order->save();
                return;
            }

            // Определяем адрес отправления
            $pointForParcelName = $order->get_meta( 'yd_reception_point' ) ? $order->get_meta( 'yd_reception_point' ) : $shippingData['object']->get_option( 'reception_point' );
            $sourceAddress = $pointForParcelName ?: get_option( 'woocommerce_store_city', '' );

            // Определяем адрес назначения и тип доставки
            $isSelfPickup = ( strpos( $shippingData['method_id'], 'yd_self' ) !== false );
            $isCod = ( strpos( $shippingData['method_id'], '_after' ) !== false );

            if ( $isSelfPickup ) {
                $yd_code = $order->get_meta( 'yd_code' );

                if ( $yd_code === '' ) {
                    $error = 'Для доставки до пункта ПВЗ нужно указать его код';
                    $order->update_meta_data( 'yd_error', $error );
                    $order->save();
                    return;
                }

                $destinationAddress = $order->get_shipping_city() ?: $order->get_billing_city();
            } else {
                $shippingCity = $order->get_shipping_city();
                if ( is_null( $shippingCity ) || trim( (string) $shippingCity ) === '' ) {
                    $shippingCity = $order->get_billing_city();
                }

                $shippingAddress = $order->get_shipping_address_1() . ', ' . $order->get_shipping_address_2();
                if ( trim( str_replace( ',', '', $shippingAddress ) ) === '' ) {
                    $shippingAddress = $order->get_billing_address_1() . ', ' . $order->get_billing_address_2();
                }

                $postCode = $order->get_shipping_postcode();
                if ( is_null( $postCode ) || trim( (string) $postCode ) === '' ) {
                    $postCode = $order->get_billing_postcode();
                }

                $destinationAddress = trim( $shippingCity . ', ' . $shippingAddress );
                if ( ! empty( $postCode ) ) {
                    $destinationAddress .= ', ' . $postCode;
                }
            }

            // Идентификатор заказа
            $orderIdForApi = ( $shippingData['object']->get_option( 'order_prefix' ) ?
                    $shippingData['object']->get_option( 'order_prefix' ) . '_' : '' )
                             . $order->get_order_number();

            // Собираем тело запроса для Yandex Delivery API
            $request_data = array(
                'info' => array(
                    'operator_request_id' => $orderIdForApi,
                    'comment'             => sprintf( 'WooCommerce заказ #%s', $order->get_order_number() ),
                ),
                'source' => array(
                    'address'   => $sourceAddress,
                    'platform_station_id' => getReceptionPointCodeByName( $pointForParcelName ) ?: '',
                ),
                'destination' => array(
                    'address'    => $destinationAddress,
                    'type'       => $isSelfPickup ? 'pickup_point' : 'door',
                ),
                'contact' => array(
                    'name'  => $customerName,
                    'phone' => $customerPhone,
                    'email' => $customerEmail,
                ),
                'items' => $items_list,
                'places' => array(
                    array(
                        'physical_dims' => array(
                            'weight_gross' => (int) $fullPackage['weight'],
                            'dx'           => max( 1, (int) $fullPackage['depth'] ),
                            'dy'           => max( 1, (int) $fullPackage['width'] ),
                            'dz'           => max( 1, (int) $fullPackage['height'] ),
                        ),
                    ),
                ),
                // P0 Fix: единая формула — копейки, с НДС, как в calculate_shipping
                'total_assessed_price' => yd_assessed_price_minor_units( $order->get_items( 'line_item' ), 'order' ),
                'total_weight'         => (int) $fullPackage['weight'],
                'tariff'               => $isSelfPickup ? 'self_pickup' : 'time_interval',
                'delivery_cost'        => (float) $shippingData['cost'],
            );

            if ( $isSelfPickup && ! empty( $yd_code ) ) {
                $request_data['destination']['platform_station_id'] = $yd_code;
            }

            if ( $isCod ) {
                $request_data['payment_method'] = 'cash_on_delivery';
                $request_data['payment_sum']    = round( $declaredCost + $shippingData['cost'], 2 );
            }

            $autoact    = (int) $shippingData['object']->get_option( 'autoact' );
            $autoStatus = $shippingData['object']->get_option( 'order_status_send' );

            $answer = $yd_client->create_request( $request_data );

            if ( is_wp_error( $answer ) ) {
                $errorMsg = $answer->get_error_message();
                $order->update_meta_data( 'yd_error', $errorMsg );
                $order->save();
                error_log( '[YD] create_request error for order #' . $orderId . ': ' . $errorMsg );
                return;
            }

            $requestId = isset( $answer['request_id'] ) ? $answer['request_id'] : '';

            if ( ! empty( $requestId ) ) {
                $order->update_meta_data( 'yd_tracking_number', $requestId );

                // Пытаемся получить ссылку на этикетку
                $labelUrl = '';
                if ( isset( $answer['label_url'] ) ) {
                    $labelUrl = $answer['label_url'];
                } else {
                    // Генерируем этикетку отдельным запросом
                    $labels = $yd_client->generate_labels( array( $requestId ) );
                    if ( ! is_wp_error( $labels ) && isset( $labels['url'] ) ) {
                        $labelUrl = $labels['url'];
                    } elseif ( ! is_wp_error( $labels ) && isset( $labels['label_url'] ) ) {
                        $labelUrl = $labels['label_url'];
                    }
                }

                $order->update_meta_data( 'yd_link', $labelUrl );
                $order->delete_meta_data( 'yd_error' );
                $order->save();

                if ( $autoact === 1 ) {
                    bxbCreateAct( $postId );
                }

                if ( $autoStatus && wc_is_order_status( $autoStatus ) ) {
                    $statusOrder = wc_get_order( $orderId );
                    if ( $statusOrder ) {
                        $statusOrder->update_status( $autoStatus, sprintf( 'Успешная регистрация в Яндекс Доставке: %s', $requestId ) );
                        do_action( 'woocommerce_yd_tracking_code', 'send', $statusOrder, $requestId );
                    }
                }
            } else {
                $order->update_meta_data( 'yd_error', 'API не вернул request_id. Ответ: ' . wp_json_encode( $answer ) );
                $order->save();
            }
        }
    }

    function bxbGetShippingData( $order )
    {
        if ( empty( $order ) ) {
            return [];
        }

        $methodId        = null;
        $exactInstanceId = null;
        $total           = 0;

        foreach ( $order->get_items( 'shipping' ) as $item ) {
            $methodId        = $item->get_method_id();
            $exactInstanceId = $item->get_instance_id();
            // Fix 2.1: include shipping tax so delivery_cost matches what customer pays
            $total           = round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );

            if ( strpos( $methodId, 'yd' ) !== false ) {
                break;
            }
        }

        if ( $methodId === null || strpos( $methodId, 'yd' ) === false ) {
            return [];
        }

        if ( $exactShippingObject = WC_Shipping_Zones::get_shipping_method( $exactInstanceId ) ) {
            return [
                'method_id' => $methodId,
                'object'    => $exactShippingObject,
                'cost'      => $total,
                'title'     => $exactShippingObject->get_option( 'title' )
            ];
        }

        global $wpdb;
        $raw_methods_sql = "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s";
        $result          = $wpdb->get_results( $wpdb->prepare( $raw_methods_sql, $methodId ) );

        if ( empty( $result ) || ! isset( $result[0]->instance_id ) ) {
            return [];
        }

        $instanceId = $result[0]->instance_id;

        if ( $shippingObject = WC_Shipping_Zones::get_shipping_method( $instanceId ) ) {
            return [
                'method_id' => $methodId,
                'object'    => $shippingObject,
                'cost'      => $total,
                'title'     => $shippingObject->get_option( 'title' )
            ];
        }

        return [];
    }

    // yd_get_tax_rate() removed — dead code, never called

    function yd_woocommerce_after_shipping_rate( $method )
    {
        if ( is_checkout() ) {
            if ( strpos( $method->get_method_id(), 'yd_self' ) !== false ) {
                $shipping = WC_Shipping_Zones::get_shipping_method( $method->get_instance_id() );
            }

            if ( isset( $shipping ) ) {
                $key     = $shipping->get_option( 'key' );
                $api_url = $shipping->get_option( 'api_url' );

                $widget_key = '';

                $billing_city  = WC()->customer->get_billing_city();
                $shipping_city = WC()->customer->get_shipping_city();
                $city          = '';

                if ( ! empty( $shipping_city ) ) {
                    $city = $shipping_city;
                } elseif ( ! empty( $billing_city ) ) {
                    $city = $billing_city;
                }

                $city = str_replace( [ 'Ё', 'Г ', 'АЛМАТЫ' ], [ 'Е', '', 'АЛМА-АТА' ], mb_strtoupper( $city ) );

                $link_title = 'Выберите пункт выдачи';

                $state = WC()->customer->get_shipping_state();

                // Единый расчёт через yd_calculate_package_dims() — без дублирования
                $rateProducts = array();
                foreach ( WC()->cart->get_cart() as $cartProduct ) {
                    $product = isset( $cartProduct['data'] ) ? $cartProduct['data'] : wc_get_product( $cartProduct['product_id'] );
                    if ( ! $product || $product->is_virtual() || $product->is_downloadable() ) {
                        continue;
                    }
                    $rateProducts[] = array(
                        'product'      => $product,
                        'quantity'     => (int) $cartProduct['quantity'],
                        'variation_id' => isset( $cartProduct['variation_id'] ) ? $cartProduct['variation_id'] : 0,
                    );
                }
                $rateDims = yd_calculate_package_dims( $rateProducts, array(
                    'default_weight'           => (float) $shipping->get_option( 'default_weight' ),
                    'default_height'           => (int) $shipping->get_option( 'default_height' ),
                    'default_depth'            => (int) $shipping->get_option( 'default_depth' ),
                    'default_width'            => (int) $shipping->get_option( 'default_width' ),
                    'apply_default_dimensions' => (int) $shipping->get_option( 'apply_default_dimensions' ),
                ) );
                $weight = $rateDims ? $rateDims['weight'] : 0;
                $height = $rateDims ? $rateDims['height'] : 0;
                $depth  = $rateDims ? $rateDims['depth'] : 0;
                $width  = $rateDims ? $rateDims['width'] : 0;

                // Сумма товаров с НДС для наложки
                $qualifiedItems = array();
                foreach ( WC()->cart->get_cart() as $ci ) {
                    if ( ! $ci['data']->is_virtual() && ! $ci['data']->is_downloadable() ) {
                        $qualifiedItems[] = $ci;
                    }
                }
                $totalval = yd_assessed_price_minor_units( $qualifiedItems, 'cart' ) / 100;

                $surch = $shipping->get_option( 'surch' ) !== '' ? (int) $shipping->get_option( 'surch' ) : 1;

                if ( $method->get_method_id() === 'yd_self_after' ) {
                    $payment = $totalval;
                } else {
                    $payment = 0;
                }

                $pvzimg                 = '<img src=\'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAYCAYAAAD6S912AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAE+SURBVHgBnVSBccMgDNR5Am9QRsgIjMIGZYN4g2QDpxN0BEagGzgbpBtQqSdiBQuC/Xc6G0m8XogDQEFKaUTzaAHtkVZEtBnNQi8w+bMgof+FTYKIzTuyS7HBKsqdIKfvqUZ2fpv0mj+JDkwZdILMQCcEaSwDuQULO8GDI7hS3VzZYFmJ09RzfFWJP981deJcU+tIhMoPWtDdSo3KJYKSe81tD7imid63zYIFHZr/h79mgDp+K/47NDBwgkG5YxG7VTZ/KT7zLIZEt8ZQjDhwusBeIZNDOcnDD3AAXPT/BkjnUlPZQTjnCUunO6KSWtyoE8HAQb+DcNmoU6ptXw+dLD91cyvJc1JUrpHM63+dROuXStyk9UW30NHKKM7mrJDl2AS9KFR4USiy7wp7kV5fm4coEOEomDQI0qk1LMIfknqE+j7lxtgAAAAASUVORK5CYII=\'>';
                $bxbbutton              = $shipping->get_option( 'bxbbutton' ) ? 'class="bxbbutton"' : '';
                $link_with_img          = $shipping->get_option( 'bxbbutton' ) ? $pvzimg : '';
                $nbsp                   = $shipping->get_option( 'bxbbutton' ) ? '&nbsp;' : '';
                $display                = $shipping->get_option( 'bxbbutton' ) ? '' : 'color:inherit;';
                $package                = WC()->shipping()->get_packages();
                $chosen_shipping_method = WC()->session->get( 'chosen_shipping_methods' );
                $targetStart = getReceptionPointCodeByName($shipping->get_option( 'reception_point' ));

                if ( isset( $package[0]['destination']['city'], $chosen_shipping_method[0] ) && $package[0]['destination']['city'] !== '' && $chosen_shipping_method[0] === $method->get_id() ) {
                    echo '                
                <p style="margin: 4px 0 8px 15px;"><a ' . $bxbbutton . ' id="' . esc_attr( $method->get_id() ) . '" href="#"
                   style="' . esc_attr( $display ) . '"
                   data-surch =" ' . esc_attr( $surch ) . '"
                   data-yandex-dostavka-open="true"
                   data-method="' . esc_attr( $method->get_method_id() ) . '"
                   data-yandex-dostavka-target-start="' . esc_attr( $targetStart ) . '"
                   data-yandex-dostavka-token="' . esc_attr( $widget_key ) . '"
                   data-yandex-dostavka-city="' . esc_attr( $state . ' ' . $city ) . '"
                   data-yandex-dostavka-weight="' . esc_attr( $weight ) . '"
                   data-paymentsum="' . esc_attr( $payment ) . '"
                   data-ordersum="' . esc_attr( $totalval ) . '"
                   data-height="' . esc_attr( $height ) . '"
                   data-width="' . esc_attr( $width ) . '"
                   data-depth="' . esc_attr( $depth ) . '"
                   data-api-url="' . esc_attr( $api_url ) . '"
                >' . $link_with_img . $nbsp . esc_html( $link_title ) . '</a></p>';
                }
            }
        }
    }

    add_action( 'woocommerce_after_shipping_rate', 'yd_woocommerce_after_shipping_rate' );

    function yd_get_widget_link_data( $shipping_method = null, $city = '', $state = '' )
    {
        static $cached_widget_data = null;

        WC()->cart->calculate_totals(); // Пересчёт корзины
        if ( $shipping_method === null ) {
            $chosen = WC()->session->get( 'chosen_shipping_methods', array() );
            if ( empty( $chosen ) || ! isset( $chosen[0] ) ) {
                return false;
            }
            $chosen_shipping_method = $chosen[0];
        } else {
            $chosen_shipping_method = $shipping_method;
        }

        $cache_key = $chosen_shipping_method . '|' . $city . '|' . $state;
        if ( $cached_widget_data !== null && isset( $cached_widget_data[ $cache_key ] ) ) {
            return $cached_widget_data[ $cache_key ];
        }

        if ( strpos( $chosen_shipping_method, 'yd_self' ) !== false ) {
            $packages = WC()->shipping()->get_packages();

            if ( ! isset( $packages[0]['rates'] ) || ! is_array( $packages[0]['rates'] ) ) {
                error_log( 'Яндекс Доставка: $packages[0]["rates"] не существует или не является массивом.' );

                return false;
            }

            if ( ! isset( $packages[0]['rates'][ $chosen_shipping_method ] ) ) {
                error_log( 'Яндекс Доставка: Метод доставки не найден в $packages[0]["rates"]. Ключи: ' . implode( ', ',
                        array_keys( $packages[0]['rates'] ) ) );

                return false;
            }

            $shipping_rate = $packages[0]['rates'][ $chosen_shipping_method ];

            if ( ! $shipping_rate ) {
                error_log( 'Яндекс Доставка: $shipping_rate равно null.' );

                return false;
            }

            $shipping_method = WC_Shipping_Zones::get_shipping_method( $shipping_rate->get_instance_id() );

            if ( ! $shipping_method ) {
                error_log( 'Яндекс Доставка: $shipping_method равно null.' );

                return false;
            }

            if ( isset( $shipping_method ) ) {
                $key = $shipping_method->get_option('key');
                $api_url = $shipping_method->get_option('api_url');

                $widget_key = '';

                $targetStart = getReceptionPointCodeByName($shipping_method->get_option( 'reception_point' ));

                $city  = !empty($city) ? $city : (WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city());
                $state = !empty($state) ? $state : (WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state());

                $city = str_replace(['Ё', 'Г ', 'АЛМАТЫ'], ['Е', '', 'АЛМА-АТА'], mb_strtoupper($city));

                // Единый расчёт веса/габаритов через yd_calculate_package_dims() (§3.2 backlog)
                $cartProducts = WC()->cart->get_cart();
                $widgetProducts = array();
                foreach ( $cartProducts as $cartProduct ) {
                    $product = isset( $cartProduct['data'] ) ? $cartProduct['data'] : wc_get_product( $cartProduct['product_id'] );
                    if ( ! $product || $product->is_virtual() || $product->is_downloadable() ) {
                        continue;
                    }
                    $widgetProducts[] = array(
                        'product'      => $product,
                        'quantity'     => (int) $cartProduct['quantity'],
                        'variation_id' => isset( $cartProduct['variation_id'] ) ? $cartProduct['variation_id'] : 0,
                    );
                }
                $widgetDims = yd_calculate_package_dims( $widgetProducts, array(
                    'default_weight'           => (float) $shipping_method->get_option( 'default_weight' ),
                    'default_height'           => (int) $shipping_method->get_option( 'default_height' ),
                    'default_depth'            => (int) $shipping_method->get_option( 'default_depth' ),
                    'default_width'            => (int) $shipping_method->get_option( 'default_width' ),
                    'apply_default_dimensions' => (int) $shipping_method->get_option( 'apply_default_dimensions' ),
                ) );
                $weight = $widgetDims ? $widgetDims['weight'] : 0;
                $height = $widgetDims ? $widgetDims['height'] : 0;
                $depth  = $widgetDims ? $widgetDims['depth'] : 0;
                $width  = $widgetDims ? $widgetDims['width'] : 0;

                $totalval = WC()->cart->get_cart_contents_total() + WC()->cart->get_total_tax();

                $surch = $shipping_method->get_option('surch') !== '' ? (int)$shipping_method->get_option('surch') : 1;

                if ($shipping_rate->get_method_id() === 'yd_self_after') {
                    $payment = $totalval;
                } else {
                    $payment = 0;
                }

                $link_title = 'Выберите пункт выдачи';

                $button = '<p style="margin: 4px 0 8px 15px;"><a class="bxbbutton" href="#"
							  style="color:inherit;"
							  data-surch =" ' . esc_attr($surch) . '"
						      data-yandex-dostavka-open="true"
							  data-method="' . esc_attr($shipping_rate->get_method_id()) . '"
							  data-yandex-dostavka-target-start="' . esc_attr($targetStart) . '"
							  data-yandex-dostavka-token="' . esc_attr($widget_key) . '"
							  data-yandex-dostavka-city="' . esc_attr($state . ' ' . $city) . '"
							  data-yandex-dostavka-weight="' . esc_attr($weight) . '"
							  data-paymentsum="' . esc_attr($payment) . '"
							  data-ordersum="' . esc_attr($totalval) . '"
							  data-height="' . esc_attr($height) . '"
							  data-width="' . esc_attr($width) . '"
							  data-depth="' . esc_attr($depth) . '"
							  data-api-url="' . esc_attr($api_url) . '"
							>' . esc_html( $link_title ) . '</a></p>';

                $cached_widget_data[ $cache_key ] = $button;

                return $button;
            }
        }

        return false;
    }

    function yd_update_widget_data()
    {
        check_ajax_referer( 'yd_update', 'security' );

        $city            = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $state           = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $shipping_method = isset( $_POST['shipping_method'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_method'] ) ) : null;

        WC()->cart->calculate_totals();

        $widget_data = yd_get_widget_link_data( $shipping_method, $city, $state );

        if ( $widget_data ) {
            wp_send_json_success( [ 'yd_widget_link' => $widget_data ] );
        } else {
            wp_send_json_error( 'Ошибка при получении данных виджета.' );
        }
    }

    add_action( 'wp_ajax_yd_update_widget_data', 'yd_update_widget_data' );
    add_action( 'wp_ajax_nopriv_yd_update_widget_data', 'yd_update_widget_data' );

    /**
     * Подсказки адреса для курьерской доставки (Dadata API).
     */
    function yd_address_suggest() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'yd_address_suggest' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        }
        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $query = trim( $query );
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( array( 'suggestions' => array() ) );
        }
        $token = get_option( 'yd_dadata_token', '' );
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Dadata token not configured' ) );
        }
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $url   = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
        $request_body = array(
            'query' => $query,
            'count' => 10,
        );
        // Для городов ограничиваем поиск уровнем city
        if ( $type === 'city' ) {
            $request_body['from_bound'] = array( 'value' => 'city' );
            $request_body['to_bound']   = array( 'value' => 'city' );
        }
        $body  = wp_json_encode( $request_body );
        $response = wp_remote_post( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => $token,
            ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $json = wp_remote_retrieve_body( $response );
        $data = json_decode( $json, true );
        if ( $code !== 200 || ! is_array( $data ) || ! isset( $data['suggestions'] ) ) {
            wp_send_json_success( array( 'suggestions' => array() ) );
        }
        $out = array();
        foreach ( $data['suggestions'] as $s ) {
            $item = array(
                'value' => isset( $s['value'] ) ? $s['value'] : '',
            );
            if ( ! empty( $s['data'] ) && is_array( $s['data'] ) ) {
                $d = $s['data'];
                $item['postal_code'] = isset( $d['postal_code'] ) ? $d['postal_code'] : '';
                $item['city']        = isset( $d['city'] ) ? $d['city'] : ( isset( $d['settlement'] ) ? $d['settlement'] : '' );
                $item['region']      = isset( $d['region'] ) ? $d['region'] : '';
                $item['street']      = isset( $d['street'] ) ? $d['street'] : '';
                $item['house']       = isset( $d['house'] ) ? $d['house'] : '';
                $item['flat']        = isset( $d['flat'] ) ? $d['flat'] : '';
            }
            if ( $item['value'] !== '' ) {
                $out[] = $item;
            }
        }
        wp_send_json_success( array( 'suggestions' => $out ) );
    }

    add_action( 'wp_ajax_yd_address_suggest', 'yd_address_suggest' );
    add_action( 'wp_ajax_nopriv_yd_address_suggest', 'yd_address_suggest' );

    /**
     * AJAX: Получить ПВЗ по городу для виджета карты.
     */
    function yd_get_pvz_points() {
        // P2 Fix: проверка nonce — используем yd_update + поле nonce (согласовано с wp_data.yd_nonce на клиенте)
        check_ajax_referer( 'yd_update', 'nonce' );

        $city = isset( $_POST['city'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['city'] ) ) ) : '';

        if ( empty( $city ) ) {
            wp_send_json_error( array( 'message' => 'City is required' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'yd_reception_points';

        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            wp_send_json_error( array( 'message' => 'Таблица ПВЗ не найдена. Сохраните настройки метода доставки.' ) );
        }

        // Проверяем есть ли координаты. Если все = 0, перезагружаем.
        $has_coords = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE lat != 0 AND lng != 0 LIMIT 1" );
        if ( ! $has_coords ) {
            $methods = array( 'yd_self', 'yd_self_after', 'yd_courier', 'yd_courier_after' );
            foreach ( $methods as $mid ) {
                $s = get_option( 'woocommerce_' . $mid . '_settings' );
                if ( is_array( $s ) && ! empty( $s['key'] ) ) {
                    yd_add_reception_points( $s['key'] );
                    break;
                }
            }
        }

        // Ищем в локальной БД по городу (LIKE — "Мытищ" найдёт "Мытищи")
        $like = '%' . $wpdb->esc_like( $city ) . '%';
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT code, name, city, lat, lng, schedule FROM `{$table}` WHERE city LIKE %s ORDER BY name LIMIT 500",
            $like
        ) );

        if ( empty( $results ) ) {
            // Второй вариант — первые 3 символа
            $short = mb_substr( $city, 0, 3 );
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT code, name, city, lat, lng, schedule FROM `{$table}` WHERE city LIKE %s ORDER BY name LIMIT 500",
                $wpdb->esc_like( $short ) . '%'
            ) );
        }

        $points = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $points[] = array(
                    'id'       => $row->code,
                    'name'     => $row->name,
                    'address'  => $row->name,
                    'lat'      => (float) $row->lat,
                    'lng'      => (float) $row->lng,
                    'schedule' => $row->schedule ?: '',
                );
            }
        }

        if ( empty( $points ) ) {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            wp_send_json_error( array( 'message' => 'ПВЗ в городе «' . $city . '» не найдены (всего в базе: ' . $total . ')' ) );
        }

        wp_send_json_success( array( 'points' => $points ) );
    }

    add_action( 'wp_ajax_yd_get_pvz_points', 'yd_get_pvz_points' );
    add_action( 'wp_ajax_nopriv_yd_get_pvz_points', 'yd_get_pvz_points' );


    function is_blocks_checkout_page()
    {
        if ( ! function_exists( 'has_block' ) ) {
            return false;
        }

        global $post;

        if ( is_checkout() && is_a( $post, 'WP_Post' ) ) {
            return has_block( 'woocommerce/checkout', $post );
        }

        return false;
    }

    function yd_frontend_enqueue( $hook )
    {
        if ( is_cart() || is_checkout() ) {
            wp_enqueue_script( 'jquery' );

            // Яндекс Карты API для виджета ПВЗ
            $ymaps_key = get_option( 'yd_ymaps_api_key', '' );
            if ( ! empty( $ymaps_key ) ) {
                wp_enqueue_script( 'ymaps', 'https://api-maps.yandex.ru/2.1/?apikey=' . urlencode( $ymaps_key ) . '&lang=ru_RU', array(), null, false );
            }

            // Виджет выбора ПВЗ
            wp_enqueue_script( 'yd_pvz_widget', plugin_dir_url( __FILE__ ) . 'js/yd-pvz-widget.js', array( 'jquery' ), '2.7.0', true );

            wp_enqueue_script( 'yd_script_handle', plugin_dir_url( __FILE__ ) . ( 'js/yandex-dostavka.js' ), [ 'jquery', 'yd_pvz_widget' ], '2.50' );

            wp_register_style( 'yd_button', plugin_dir_url( __FILE__ ) . 'css/yandex-dostavka.css', array(), '3.1.0' );

            wp_enqueue_style( 'yd_button' );

            $city  = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
            $state = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();

            $widget_data = yd_get_widget_link_data( null, $city, $state );

            if ( is_blocks_checkout_page() ) {
                wp_enqueue_script(
                    'yandex-dostavka-react-app',
                    plugin_dir_url( __FILE__ ) . 'dist/bundle.js',
                    [ 'wp-element', 'wp-i18n', 'wp-plugins', 'wc-blocks-checkout', 'jquery' ],
                    null,
                    true
                );

                wp_localize_script(
                    'yandex-dostavka-react-app',
                    'wp_data',
                    [
                        'ajax_url'             => admin_url( 'admin-ajax.php' ),
                        'yd_nonce'       => wp_create_nonce( 'yd_update' ),
                        'yd_address_suggest_nonce' => wp_create_nonce( 'yd_address_suggest' ),
                        'yd_widget_link' => $widget_data,
                    ]
                );
            }
        }
    }

    add_action( 'wp_enqueue_scripts', 'yd_frontend_enqueue' );

    function yd_admin_enqueue( $hook )
    {
        // Загружаем скрипты только на страницах заказов и настроек WC
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true )
             && strpos( $hook, 'wc-settings' ) === false ) {
            return;
        }

        wp_enqueue_script( 'jquery-ui-autocomplete' );
        wp_enqueue_script( 'yd_script_handle', plugin_dir_url( __FILE__ ) . ( 'js/yandex-dostavka-admin.js' ), array( 'jquery', 'jquery-ui-autocomplete' ), '2.34' );
    }

    add_action( 'admin_enqueue_scripts', 'yd_admin_enqueue' );

    function yd_save_pickup_point_block_checkout( $order )
    {
        $needs_save = false;
        $shipping_methods = $order->get_shipping_methods();
        foreach ( $shipping_methods as $shipping_method ) {
            $method_id = $shipping_method->get_method_id();

            if ( strpos( $method_id, 'yd_self' ) !== false ) {
                if ( isset( $_COOKIE['yd_pvz_code'] ) ) {
                    $order->update_meta_data( 'yd_code', sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) );
                    $order->update_meta_data( 'yd_address', sanitize_text_field( wp_unslash( isset( $_COOKIE['yd_pvz_address'] ) ? $_COOKIE['yd_pvz_address'] : '' ) ) );
                    $needs_save = true;
                }
            }
        }

        if ( $needs_save ) {
            $order->save();
        }
    }

    add_action( 'woocommerce_store_api_checkout_order_processed', 'yd_save_pickup_point_block_checkout', 10, 1 );

    function yd_block_checkout_validation( $result, $server, $request ) {
        $route  = $request->get_route();
        $method = $request->get_method();

        if ( 'POST' === $method && false !== strpos( $route, '/wc/store/v1/checkout' ) ) {
            if ( null === WC()->session ) {
                if ( class_exists( 'WC_Session_Handler' ) ) {
                    WC()->session = new WC_Session_Handler();
                    WC()->session->init();
                } else {
                    return $result;
                }
            }

            $chosen = WC()->session->get( 'chosen_shipping_methods', [] );

            if ( is_array( $chosen )
                 && ! empty( $chosen[0] )
                 && ( strpos( $chosen[0], 'yd_self' ) !== false )
                 && empty( $_COOKIE['yd_pvz_code'] )
            ) {
                return new \WP_Error(
                    'yd_missing_pvz',
                    '<strong>Необходимо выбрать пункт выдачи Яндекс Доставки</strong>',
                    [ 'status' => 400 ]
                );
            }
        }

        return $result;
    }

    add_filter( 'rest_pre_dispatch', 'yd_block_checkout_validation', 10, 3 );

    function yd_put_choice_code( $order_id )
    {
        $order = wc_get_order( $order_id );

        if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) && ! empty( $_POST['shipping_method'] ) ) {
            $methods = array_map( function ( $m ) {
                return sanitize_text_field( wp_unslash( $m ) );
            }, (array) $_POST['shipping_method'] );
            $shipping_method       = array_shift( $methods );
            $shipping_method_parts = explode( ':', $shipping_method );
            $shipping_method_name  = $shipping_method_parts[0];

            if ( in_array( $shipping_method_name, [
                'yd_self_after',
                'yd_self',
                'yd_courier_after',
                'yd_courier'
            ] ) ) {
                if ( isset( $_COOKIE['yd_pvz_code'], $_COOKIE['yd_pvz_address'] ) ) {
                    $order->update_meta_data( 'yd_code', sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) );
                    $order->update_meta_data( 'yd_address', sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) );
                    $order->save();
                }
                if ( get_current_user_id() > 0 ) {
                    update_user_meta( get_current_user_id(), '_yd_array', array() );
                }
            }
        }
    }

    add_action( 'woocommerce_new_order', 'yd_put_choice_code' );

    function yd_update_callback()
    {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'yd_update' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
        }
        $code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
        if ( $code ) {
            setcookie( 'yd_pvz_code', $code, array(
                'expires'  => 0,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ) );
        }
        if ( $address ) {
            setcookie( 'yd_pvz_address', $address, array(
                'expires'  => 0,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ) );
        }

        // Принудительно чистим кэш shipping rates WooCommerce при смене ПВЗ,
        // чтобы update_checkout пересчитал цену с новым platform_station_id
        if ( $code && function_exists( 'WC' ) && WC()->session ) {
            $packages = WC()->cart ? WC()->cart->get_shipping_packages() : array();
            foreach ( $packages as $key => $pkg ) {
                WC()->session->__unset( 'shipping_for_package_' . $key );
            }
        }

        wp_send_json_success();
    }

    add_action( 'wp_ajax_yd_update', 'yd_update_callback' );
    add_action( 'wp_ajax_nopriv_yd_update', 'yd_update_callback' );

    function yd_admin_update_callback()
    {
        check_ajax_referer( 'yd_admin', 'nonce' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ) );
        }
        $postId = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';

        if ( $postId && ( $order = wc_get_order( $postId ) ) ) {
            $order->update_meta_data( 'yd_code', $code );
            $order->update_meta_data( 'yd_address', $address );
            $order->save();
        }
        wp_send_json_success();
    }

    function yd_admin_reception_point_update_callback() {
        check_ajax_referer( 'yd_admin', 'nonce' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ) );
        }
        $postId = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;
        $receptionPoint = isset( $_POST['point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['point_id'] ) ) : '';

        if ( $postId && $receptionPoint && ( $order = wc_get_order( $postId ) ) ) {
            $order->update_meta_data( 'yd_reception_point', $receptionPoint );
            $order->save();
        }
        wp_send_json_success();
    }

    // yd_is_point_yandex_by_name() removed — dead code
    // yd_is_point_yandex_by_code() removed — dead code
    // yd_get_current_api_data_source() removed — dead code (always returned 'yandex')

    function yd_is_reception_points_table_exist()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_reception_points';
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        return !empty($result);
    }

    function yd_is_cities_table_exist()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_cities';
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        return !empty($result);
    }

    function yd_create_reception_points_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_reception_points';

        if (yd_is_reception_points_table_exist()) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        code varchar(255) NOT NULL,
        name varchar(500) NOT NULL,
        city varchar(255) NOT NULL,
        lat decimal(10,7) DEFAULT 0,
        lng decimal(10,7) DEFAULT 0,
        schedule varchar(255) DEFAULT '',
        PRIMARY KEY  (id),
        INDEX idx_city (city)
    ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    function yd_create_cities_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_cities';

        if (yd_is_cities_table_exist()) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            `id` int(11) NOT NULL AUTO_INCREMENT,
    `code` varchar(255) NOT NULL,
    `name` varchar(255) NOT NULL,
    `region` varchar(255) NOT NULL,
    `kladr` varchar(255) NOT NULL,
    `country_code` varchar(255) NOT NULL,
    `uniq_name` varchar(255) NOT NULL,
    `district` varchar(255) NOT NULL,
    `pickup_point` int(11) NOT NULL,
    `courier_delivery` int(11) NOT NULL,
    `prefix` varchar(255) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    function yd_is_reception_points_table_empty()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_reception_points';
        $query = "SELECT COUNT(*) FROM $table_name";
        $count = $wpdb->get_var($query);

        return $count == 0;
    }

    // yd_is_cities_table_empty() removed — dead code, never called
    // yd_add_cities() removed — was no-op, never needed

    function yd_add_reception_points($token)
    {
        if (!$token) {
            error_log('[YD] add_reception_points: token is empty');
            return array( 'success' => false, 'message' => 'Токен не указан' );
        }

        if (!yd_is_reception_points_table_exist()) {
            yd_create_reception_points_table();
            error_log('[YD] add_reception_points: table created');
        }

        // Загружаем точки самопривоза из Yandex Delivery API
        $response = wp_remote_post(
            'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform/pickup-points/list',
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization'  => 'Bearer ' . $token,
                    'Content-Type'   => 'application/json',
                    'Accept-Language' => 'ru',
                ),
                'body' => wp_json_encode( array( 'available_for_dropoff' => true ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            error_log('[YD] add_reception_points HTTP error: ' . $err_msg);
            return array( 'success' => false, 'message' => 'Ошибка HTTP: ' . $err_msg );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $status_code !== 200 ) {
            $err_msg = isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $status_code;
            error_log('[YD] add_reception_points API error: ' . $err_msg);
            return array( 'success' => false, 'message' => 'Ошибка API: ' . $err_msg );
        }

        if ( ! is_array( $data ) || empty( $data['points'] ) ) {
            error_log('[YD] add_reception_points: API returned empty points');
            return array( 'success' => false, 'message' => 'API не вернул точек самопривоза' );
        }

        $points = $data['points'];
        error_log('[YD] add_reception_points: API returned ' . count($points) . ' points');

        // Сохраняем в таблицу
        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_reception_points';
        $wpdb->query("TRUNCATE TABLE `{$table_name}`");

        $inserted = 0;
        foreach ( $points as $point ) {
            if ( empty( $point['id'] ) ) {
                continue;
            }
            $addr = isset( $point['address'] ) ? $point['address'] : array();
            $city = isset( $addr['locality'] ) ? $addr['locality'] : '';
            $street = isset( $addr['street'] ) ? $addr['street'] : '';
            $house = isset( $addr['house'] ) ? $addr['house'] : '';
            // Формат: "Москва, Судостроительная улица, 59"
            $name_parts = array_filter( array( $city, $street, $house ) );
            $name = implode( ', ', $name_parts );
            if ( empty( $name ) ) {
                $name = isset( $addr['full_address'] ) ? $addr['full_address'] : '';
            }
            if ( empty( $name ) ) {
                $name = isset( $point['name'] ) ? $point['name'] : $point['id'];
            }

            $lat = isset( $point['position']['latitude'] ) ? $point['position']['latitude'] : 0;
            $lng = isset( $point['position']['longitude'] ) ? $point['position']['longitude'] : 0;
            $schedule = '';
            if ( ! empty( $point['schedule']['restrictions'] ) ) {
                $r = $point['schedule']['restrictions'][0];
                $schedule = sprintf( '%02d:%02d — %02d:%02d',
                    $r['time_from']['hours'], $r['time_from']['minutes'],
                    $r['time_to']['hours'], $r['time_to']['minutes']
                );
            }

            $result = $wpdb->insert(
                $table_name,
                array(
                    'code'     => $point['id'],
                    'name'     => $name,
                    'city'     => $city,
                    'lat'      => $lat,
                    'lng'      => $lng,
                    'schedule' => $schedule,
                ),
                array( '%s', '%s', '%s', '%f', '%f', '%s' )
            );
            if ( $result ) {
                $inserted++;
            }
        }

        error_log('[YD] add_reception_points: inserted ' . $inserted . ' of ' . count($points));
        return array( 'success' => true, 'count' => $inserted );
    }

    function yd_admin_check_api_key_callback()
    {
        check_ajax_referer( 'yd_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ) );
        }
        $key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => 'Токен не указан' ) );
        }

        // Проверяем токен через Yandex Delivery API (delivery-methods)
        $test_response = wp_remote_post(
            'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform/pickup-points/list',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $key,
                    'Content-Type'    => 'application/json',
                    'Accept-Language' => 'ru',
                ),
                'body' => wp_json_encode( array( 'available_for_dropoff' => true ) ),
            )
        );

        if ( is_wp_error( $test_response ) ) {
            wp_send_json_error( array( 'message' => 'Ошибка подключения к Yandex API: ' . $test_response->get_error_message() ) );
        }

        $status_code = wp_remote_retrieve_response_code( $test_response );
        if ( $status_code === 401 || $status_code === 403 ) {
            wp_send_json_error( array( 'message' => 'Токен невалиден или не имеет доступа (HTTP ' . $status_code . ')' ) );
        }
        if ( $status_code !== 200 ) {
            $body = wp_remote_retrieve_body( $test_response );
            $err_data = json_decode( $body, true );
            $err_msg = isset( $err_data['message'] ) ? $err_data['message'] : 'HTTP ' . $status_code;
            wp_send_json_error( array( 'message' => 'Ошибка Yandex API: ' . $err_msg ) );
        }

        // Токен валиден — загружаем пункты приёма если таблица пуста
        if (!yd_is_reception_points_table_exist() || yd_is_reception_points_table_empty()) {
            $populate_result = yd_add_reception_points($key);
            if ( is_array($populate_result) && empty($populate_result['success']) ) {
                wp_send_json_error( array(
                    'message' => 'Токен валиден, но пункты приёма не загрузились: ' . ( isset($populate_result['message']) ? $populate_result['message'] : 'неизвестная ошибка' ),
                ) );
            }
        }

        wp_send_json_success( array( 'message' => 'Yandex API токен проверен.' ) );
    }

    function yd_admin_company_api_settings_callback()
    {
        check_ajax_referer( 'yd_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ) );
        }
        $key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => 'Токен не указан' ) );
        }

        $yd_client = new Yandex_Delivery_API( $key );
        if ( $yd_client->validate_token() ) {
            wp_send_json_success( array( 'settings' => array( 'ya_delivery' => true ) ) );
        } else {
            wp_send_json_error( array( 'message' => 'Токен невалиден: ' . ( $yd_client->get_last_error() ?: 'неизвестная ошибка' ) ) );
        }
    }

    function yd_admin_reception_point_search_callback()
    {
        check_ajax_referer( 'yd_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ) );
        }
        $searchTerm = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

        if ( ! $searchTerm ) {
            wp_send_json( array( array( 'label' => 'Введите минимум 2 символа.', 'value' => '' ) ) );
            return;
        }

        // Проверяем существование таблицы
        if ( ! yd_is_reception_points_table_exist() ) {
            wp_send_json( array( array( 'label' => 'Таблица пунктов приёма не создана. Сохраните настройки с корректным API-токеном.', 'value' => '' ) ) );
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'yd_reception_points';

        // Проверяем наличие данных, при пустой таблице пробуем загрузить
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );
        if ( $count === 0 ) {
            // Пробуем найти API-ключ из настроек и загрузить пункты
            $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
            if ( empty( $api_key ) ) {
                // Ищем ключ в сохранённых настройках WooCommerce
                $method_ids = array( 'yd_self', 'yd_self_after', 'yd_courier', 'yd_courier_after' );
                foreach ( $method_ids as $mid ) {
                    $saved_key = get_option( 'woocommerce_' . $mid . '_settings' );
                    if ( is_array( $saved_key ) && ! empty( $saved_key['key'] ) ) {
                        $api_key = $saved_key['key'];
                        break;
                    }
                }
            }
            if ( ! empty( $api_key ) ) {
                $populate_result = yd_add_reception_points( $api_key );
                if ( is_array( $populate_result ) && ! empty( $populate_result['success'] ) ) {
                    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );
                } else {
                    $err_msg = is_array( $populate_result ) && isset( $populate_result['message'] ) ? $populate_result['message'] : 'Неизвестная ошибка';
                    wp_send_json( array( array( 'label' => $err_msg, 'value' => '' ) ) );
                    return;
                }
            }
            if ( $count === 0 ) {
                wp_send_json( array( array( 'label' => 'Таблица пуста. Проверьте API-токен (не ID клиента).', 'value' => '' ) ) );
                return;
            }
        }

        $like = '%' . $wpdb->esc_like($searchTerm) . '%';
        $query = $wpdb->prepare(
            "SELECT * FROM `{$table_name}` WHERE name LIKE %s OR city LIKE %s ORDER BY city, name LIMIT 20",
            $like, $like
        );

        $results = $wpdb->get_results($query);

        $items = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $items[] = array(
                    'label' => $row->name,
                    'value' => $row->code,
                );
            }
        }

        if ( empty( $items ) ) {
            $items[] = array( 'label' => 'Не найдено: «' . $searchTerm . '» (всего пунктов: ' . $count . ')', 'value' => '' );
        }

        wp_send_json( $items );
    }

    add_action( 'wp_ajax_yd_admin_update', 'yd_admin_update_callback' );
    add_action( 'wp_ajax_yd_admin_reception_point_update', 'yd_admin_reception_point_update_callback' );
    add_action( 'wp_ajax_yd_admin_check_api_key', 'yd_admin_check_api_key_callback' );
    add_action( 'wp_ajax_yd_admin_company_api_settings', 'yd_admin_company_api_settings_callback' );
    add_action( 'wp_ajax_yd_admin_reception_point_search', 'yd_admin_reception_point_search_callback' );

    function yd_js_variables()
    {
        $variables = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'yd_nonce' => wp_create_nonce( 'yd_update' ),
            'yd_address_suggest_nonce' => wp_create_nonce( 'yd_address_suggest' ),
        );
        echo '<script type="text/javascript">';
        echo 'window.wp_data = window.wp_data ? Object.assign(window.wp_data, ';
        echo wp_json_encode( $variables );
        echo ') : ';
        echo wp_json_encode( $variables );
        echo ';</script>';

        // CSS: прячем лишние поля чекаута когда единственный метод — ПВЗ
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            $only_pvz = true;
            $shipping = WC()->shipping();
            $packages = ( $shipping && method_exists( $shipping, 'get_packages' ) ) ? $shipping->get_packages() : array();
            if ( WC()->cart && ! empty( $packages ) ) {
                foreach ( $packages as $pkg ) {
                    if ( ! empty( $pkg['rates'] ) ) {
                        foreach ( $pkg['rates'] as $rate_id => $rate ) {
                            if ( strpos( $rate_id, 'yd_courier' ) !== false ) {
                                $only_pvz = false;
                                break 2;
                            }
                        }
                    }
                }
            }
            if ( $only_pvz ) {
                echo '<style>
                    #billing_address_1_field, #shipping_address_1_field,
                    #billing_address_2_field, #shipping_address_2_field,
                    #billing_postcode_field, #shipping_postcode_field,
                    #billing_state_field, #shipping_state_field,
                    .wc-block-components-address-form__address_1,
                    .wc-block-components-address-form__address_2,
                    .wc-block-components-address-form__postcode,
                    .wc-block-components-address-form__state {
                        display: none !important;
                    }
                </style>';
            }
        }
    }

    add_action( 'wp_head', 'yd_js_variables' );

    function yd_admin_js_variables()
    {
        $variables = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'yd_admin_nonce' => wp_create_nonce( 'yd_admin' ),
        );
        echo '<script type="text/javascript">';
        echo 'window.wp_data = ';
        echo wp_json_encode( $variables );
        echo ';</script>';
        // CSS для jQuery UI Autocomplete (выпадающий список пунктов приёма)
        echo '<style>
            .ui-autocomplete {
                max-height: 250px;
                overflow-y: auto;
                overflow-x: hidden;
                background: #fff;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                list-style: none;
                margin: 2px 0 0;
                padding: 0;
                z-index: 100000;
            }
            .ui-autocomplete .ui-menu-item {
                margin: 0;
                padding: 0;
            }
            .ui-autocomplete .ui-menu-item-wrapper {
                display: block;
                padding: 8px 12px;
                cursor: pointer;
                border-bottom: 1px solid #f0f0f1;
                color: #1d2327;
                font-size: 13px;
            }
            .ui-autocomplete .ui-menu-item-wrapper.ui-state-active,
            .ui-autocomplete .ui-menu-item-wrapper:hover {
                background: #2271b1;
                color: #fff;
            }
        </style>';
    }

    add_action( 'admin_head', 'yd_admin_js_variables' );

    function yd_register_on_status( $orderId, $previous_status, $next_status )
    {
        $order        = wc_get_order( $orderId );
        $shippingData = bxbGetShippingData( $order );

        if ( isset( $shippingData['method_id'], $shippingData['object'] ) && strpos( $shippingData['method_id'], 'yd' ) !== false ) {
            $parselCreateStatus = $shippingData['object']->get_option( 'parselcreate_on_status' );

            // Fix: guard against 'none' default — substr('none',3) === 'e' which could match accidentally
            if ( $parselCreateStatus !== 'none' && $next_status === substr( $parselCreateStatus, 3 ) && ! $order->get_meta( 'yd_tracking_number' ) ) {
                yd_get_tracking_code( $orderId );
            }
        }
    }

    add_action( 'woocommerce_order_status_changed', 'yd_register_on_status', 10, 3 );

    /**
     * Валидация чекаута для Яндекс Доставки.
     * Учитывает гостевой заказ без регистрации: минимум полей (адрес, телефон — из настроек WC),
     * уведомления клиенту только по email (обрабатывает WooCommerce).
     */
    /**
     * Перед валидацией: заполняем пустые поля для ПВЗ (ЮKassa требует billing_address, postcode).
     */
    function yd_fill_pvz_billing_fields( $data ) {
        $methods = isset( $data['shipping_method'] ) ? (array) $data['shipping_method'] : array();
        $parts = isset( $methods[0] ) ? explode( ':', $methods[0] ) : array( '' );
        $method = $parts[0];
        if ( strpos( $method, 'yd_self' ) !== false ) {
            $pvz_address = isset( $_COOKIE['yd_pvz_address'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) : 'ПВЗ Яндекс Доставки';
            if ( empty( $data['billing_address_1'] ) ) {
                $data['billing_address_1'] = $pvz_address;
                $_POST['billing_address_1'] = $pvz_address;
            }
            if ( empty( $data['billing_postcode'] ) ) {
                $data['billing_postcode'] = '111111';
                $_POST['billing_postcode'] = '111111';
            }
            if ( empty( $data['billing_state'] ) ) {
                $data['billing_state'] = $data['billing_city'];
                $_POST['billing_state'] = $data['billing_city'];
            }
            if ( empty( $data['billing_country'] ) ) {
                // Default country filterable for KZ, BY, etc. — was hardcoded 'RU'
                $defaultCountry = apply_filters( 'yd_default_billing_country', get_option( 'woocommerce_default_country', 'RU' ) );
                // WC stores country:state format, extract just country
                $defaultCountry = strstr( $defaultCountry, ':', true ) ?: $defaultCountry;
                $data['billing_country'] = $defaultCountry;
                $_POST['billing_country'] = $defaultCountry;
            }
            if ( empty( $data['shipping_address_1'] ) ) {
                $data['shipping_address_1'] = $pvz_address;
                $_POST['shipping_address_1'] = $pvz_address;
            }
            if ( empty( $data['shipping_postcode'] ) ) {
                $data['shipping_postcode'] = $data['billing_postcode'];
                $_POST['shipping_postcode'] = $data['billing_postcode'];
            }
            if ( empty( $data['shipping_country'] ) ) {
                $data['shipping_country'] = 'RU';
                $_POST['shipping_country'] = 'RU';
            }
        }
        return $data;
    }
    add_filter( 'woocommerce_checkout_posted_data', 'yd_fill_pvz_billing_fields', 5 );

    function yd_validate_checkout( $data, $errors )
    {
        if ( ! empty( $errors->get_error_message( 'shipping' ) ) ) {
            return;
        }

        $shipping_methods = isset( $data['shipping_method'] ) ? (array) $data['shipping_method'] : array();
        $shippingMethod   = array_map( static function ( $i ) {
            $parts = explode( ':', (string) $i );
            return $parts[0];
        }, $shipping_methods );

        $method = isset( $shippingMethod[0] ) ? $shippingMethod[0] : '';
        if ( strpos( $method, 'yd' ) === false ) {
            return;
        }

        $ship_to_different = ! empty( $data['ship_to_different_address'] );
        $city = $ship_to_different
            ? ( isset( $data['shipping_city'] ) ? trim( (string) $data['shipping_city'] ) : '' )
            : ( isset( $data['billing_city'] ) ? trim( (string) $data['billing_city'] ) : '' );

        if ( $city === '' ) {
            $errors->add( 'shipping', '<strong>Необходимо указать город для доставки Яндекс Доставки</strong>' );
            return;
        }

        if ( strpos( $method, 'yd_self' ) !== false ) {
            $chosenDeliveryPoint = isset( $_POST['yd_code'] ) ? sanitize_text_field( wp_unslash( $_POST['yd_code'] ) ) : ( isset( $_COOKIE['yd_pvz_code'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) : '' );
            if ( $chosenDeliveryPoint === '' ) {
                $errors->add( 'shipping', '<strong>Необходимо выбрать пункт выдачи Яндекс Доставки</strong>' );
            }
        } elseif ( strpos( $method, 'yd_courier' ) !== false ) {
            $address = $ship_to_different
                ? ( isset( $data['shipping_address_1'] ) ? trim( (string) $data['shipping_address_1'] ) : '' )
                : ( isset( $data['billing_address_1'] ) ? trim( (string) $data['billing_address_1'] ) : '' );
            if ( $address === '' ) {
                $errors->add( 'shipping', '<strong>Необходимо указать адрес доставки</strong>' );
            }
        }
    }

    add_action( 'woocommerce_after_checkout_validation', 'yd_validate_checkout', 10, 2 );

    /**
     * Минимизация полей чекаута: оставляем только имя, фамилию, телефон, email, город, страну, регион.
     * Адрес доставки показывается/скрывается через JS в зависимости от выбранного метода (курьер/ПВЗ).
     *
     * Применяем ТОЛЬКО если среди доступных способов доставки есть yd.
     */
    function yd_customize_checkout_fields( $fields ) {
        // Проверяем, есть ли yd среди доступных тарифов
        $has_yd = false;
        $packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
        foreach ( $packages as $pkg ) {
            if ( ! empty( $pkg['rates'] ) ) {
                foreach ( $pkg['rates'] as $rate_id => $rate ) {
                    if ( strpos( $rate_id, 'yd' ) !== false ) {
                        $has_yd = true;
                        break 2;
                    }
                }
            }
        }
        if ( ! $has_yd ) {
            return $fields;
        }

        // Убираем лишнее: company, order_comments
        unset( $fields['billing']['billing_company'] );
        unset( $fields['shipping']['shipping_company'] );
        unset( $fields['order']['order_comments'] );

        // State скрываем (не удаляем — WooCommerce использует для зон/налогов)
        if ( isset( $fields['billing']['billing_state'] ) ) {
            $fields['billing']['billing_state']['required'] = false;
            $fields['billing']['billing_state']['class']    = array( 'form-row-wide', 'hidden' );
            $fields['billing']['billing_state']['custom_attributes']['style'] = 'display:none';
        }
        if ( isset( $fields['shipping']['shipping_state'] ) ) {
            $fields['shipping']['shipping_state']['required'] = false;
            $fields['shipping']['shipping_state']['class']    = array( 'form-row-wide', 'hidden' );
            $fields['shipping']['shipping_state']['custom_attributes']['style'] = 'display:none';
        }

        // address_2 — доп. поле для курьера (подъезд, этаж, квартира)
        if ( isset( $fields['billing']['billing_address_2'] ) ) {
            $fields['billing']['billing_address_2']['label']       = 'Подъезд, этаж, квартира, домофон';
            $fields['billing']['billing_address_2']['placeholder'] = 'Например: подъезд 2, этаж 5, кв. 18';
            $fields['billing']['billing_address_2']['required']    = false;
        }
        if ( isset( $fields['shipping']['shipping_address_2'] ) ) {
            $fields['shipping']['shipping_address_2']['label']       = 'Подъезд, этаж, квартира, домофон';
            $fields['shipping']['shipping_address_2']['placeholder'] = 'Например: подъезд 2, этаж 5, кв. 18';
            $fields['shipping']['shipping_address_2']['required']    = false;
        }

        // Адрес не обязателен — для ПВЗ он не нужен, для курьера валидируем отдельно
        if ( isset( $fields['billing']['billing_address_1'] ) ) {
            $fields['billing']['billing_address_1']['required'] = false;
        }
        if ( isset( $fields['shipping']['shipping_address_1'] ) ) {
            $fields['shipping']['shipping_address_1']['required'] = false;
        }

        // Индекс не обязателен — для ПВЗ не нужен
        if ( isset( $fields['billing']['billing_postcode'] ) ) {
            $fields['billing']['billing_postcode']['required'] = false;
        }
        if ( isset( $fields['shipping']['shipping_postcode'] ) ) {
            $fields['shipping']['shipping_postcode']['required'] = false;
        }

        return $fields;
    }

    add_filter( 'woocommerce_checkout_fields', 'yd_customize_checkout_fields', 20 );
    add_filter( 'woocommerce_checkout_fields', 'yd_phone_always_required', 25 );
}

// Телефон обязателен ВСЕГДА, независимо от способа доставки
function yd_phone_always_required( $fields ) {
    if ( isset( $fields['billing']['billing_phone'] ) ) {
        $fields['billing']['billing_phone']['required'] = true;
    }
    return $fields;
}

function yd_update_api_data()
{
    $token = '';
    $zones = WC_Shipping_Zones::get_zones();

    foreach ( $zones as $zone ) {
        if ( ! empty( $zone['shipping_methods'] ) ) {
            foreach ( $zone['shipping_methods'] as $method ) {
                if ( strpos( $method->id, 'yd' ) !== false && $method->is_enabled() ) {
                    $key = $method->get_option( 'key' );
                    if ( ! empty( $key ) ) {
                        $token = $key;
                        break 2;
                    }
                }
            }
        }
    }

    if ( ! $token ) {
        return;
    }

    yd_add_reception_points( $token );
    yd_add_cities( $token );
}

function getReceptionPointCodeByName($name)
{
    global $wpdb;

    $name = sanitize_text_field($name);

    $query = $wpdb->prepare(
        "SELECT code FROM {$wpdb->prefix}yd_reception_points WHERE name = %s",
        $name
    );

    $result = $wpdb->get_var($query);

    if ($result) {
        return $result;
    } else {
        return null;
    }
}

/**
 * Миграция настроек с nemirov_dostavka_ на yd_ (одноразово при активации).
 */
function yd_migrate_from_nemirov() {
    if ( get_option( 'yd_migrated_from_nemirov' ) ) {
        return;
    }

    global $wpdb;

    // 1. Миграция WC shipping method settings
    $old_methods = array(
        'nemirov_dostavka_self'         => 'yd_self',
        'nemirov_dostavka_self_after'   => 'yd_self_after',
        'nemirov_dostavka_courier'      => 'yd_courier',
        'nemirov_dostavka_courier_after'=> 'yd_courier_after',
    );
    foreach ( $old_methods as $old_id => $new_id ) {
        $old_key = 'woocommerce_' . $old_id . '_settings';
        $new_key = 'woocommerce_' . $new_id . '_settings';
        $old_val = get_option( $old_key );
        if ( $old_val && ! get_option( $new_key ) ) {
            update_option( $new_key, $old_val );
        }
    }

    // 2. Миграция order meta keys
    $meta_keys = array(
        'nemirov_dostavka_tracking_number' => 'yd_tracking_number',
        'nemirov_dostavka_code'            => 'yd_code',
        'nemirov_dostavka_address'         => 'yd_address',
        'nemirov_dostavka_act_link'        => 'yd_act_link',
        'nemirov_dostavka_error'           => 'yd_error',
    );
    foreach ( $meta_keys as $old_meta => $new_meta ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
            $new_meta, $old_meta
        ) );
        // HPOS: wc_orders_meta
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'" ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wc_orders_meta SET meta_key = %s WHERE meta_key = %s",
                $new_meta, $old_meta
            ) );
        }
    }

    // 3. Переименование таблиц
    $old_tables = array(
        'nemirov_dostavka_reception_points' => 'yd_reception_points',
        'nemirov_dostavka_cities'           => 'yd_cities',
    );
    foreach ( $old_tables as $old_suffix => $new_suffix ) {
        $old_table = $wpdb->prefix . $old_suffix;
        $new_table = $wpdb->prefix . $new_suffix;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) ) {
            $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
        }
    }

    // 4. Переименование shipping method в зонах (если таблица существует)
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_shipping_zone_methods'" ) ) {
        $wpdb->query( "UPDATE {$wpdb->prefix}woocommerce_shipping_zone_methods SET method_id = REPLACE(method_id, 'nemirov_dostavka_', 'yd_') WHERE method_id LIKE 'nemirov_dostavka_%'" );
    }

    // 5. Переименование заголовков
    $options = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_yd_%_settings'"
    );
    if ( $options ) {
        foreach ( $options as $option ) {
            $settings = maybe_unserialize( $option->option_value );
            if ( is_array( $settings ) && isset( $settings['title'] ) ) {
                $settings['title'] = str_replace( array( 'Yandex Dostavka', 'Boxberry' ), 'Яндекс Доставка', $settings['title'] );
                update_option( $option->option_name, $settings );
            }
        }
    }

    update_option( 'yd_migrated_from_nemirov', 1 );
    error_log( '[YD] Migration from nemirov_dostavka_ to yd_ completed' );
}

register_activation_hook( __FILE__, 'yd_migrate_from_nemirov' );
add_action( 'plugins_loaded', 'yd_migrate_from_nemirov', 1 );