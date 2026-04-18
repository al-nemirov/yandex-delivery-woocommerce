<?php
/*
Plugin Name: Яндекс Доставка для WooCommerce
Plugin URI: https://github.com/al-nemirov/yandex-delivery-woocommerce
Description: Интеграция WooCommerce с Яндекс Доставкой: расчёт стоимости, выбор ПВЗ, выгрузка заказов, автоматическая синхронизация статусов
Version: 2.16.2-beta
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
add_filter( 'upgrader_post_install', 'yd_github_post_install', 10, 3 );

// Clear cache when WP force-checks ("Check again" on Updates page)
if ( is_admin() && isset( $_GET['force-check'] ) ) {
    delete_transient( 'yd_github_release' );
    delete_transient( 'yd_github_readme_changelog' );
}

/**
 * HTTP-заголовки для GitHub API (обязателен User-Agent, иначе 403).
 *
 * @return array
 */
function yd_github_api_request_args() {
    return array(
        'timeout' => 12,
        'headers' => array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'YandexDostavka-WordPress-Updater/1.0 (+https://github.com/al-nemirov/yandex-delivery-woocommerce)',
        ),
    );
}

/**
 * Последний опубликованный релиз (включая pre-release).
 * В отличие от /releases/latest, не игнорирует черновики помеченные как pre-release на GitHub.
 *
 * @return array|null Декодированный JSON одного релиза или null
 */
function yd_github_get_latest_release_payload() {
    $url      = 'https://api.github.com/repos/al-nemirov/yandex-delivery-woocommerce/releases?per_page=10';
    $response = wp_remote_get( $url, yd_github_api_request_args() );

    if ( is_wp_error( $response ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[YD Updater] GitHub request error: ' . $response->get_error_message() );
        }
        return null;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    if ( $code !== 200 ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[YD Updater] GitHub HTTP ' . $code . ': ' . mb_substr( $body, 0, 500 ) );
        }
        return null;
    }

    $list = json_decode( $body, true );
    if ( ! is_array( $list ) ) {
        return null;
    }

    foreach ( $list as $item ) {
        if ( ! is_array( $item ) || ! empty( $item['draft'] ) ) {
            continue;
        }
        if ( empty( $item['tag_name'] ) ) {
            continue;
        }
        return $item;
    }

    return null;
}

function yd_github_check_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( __FILE__ ); // yandex-dostavka/yandex-dostavka.php
    $plugin_data = get_plugin_data( __FILE__ );
    $current_ver = $plugin_data['Version'];

    $cache_key = 'yd_github_release';
    $release   = get_transient( $cache_key );

    if ( false === $release ) {
        $release = yd_github_get_latest_release_payload();
        if ( is_array( $release ) ) {
            set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
        }
    }

    if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
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

/**
 * Загружает и парсит секцию == Changelog == из readme.txt на GitHub для заданного тега.
 *
 * Зачем: у нас исторически длинный changelog в readme.txt, а body GitHub-релиза часто
 * автогенерируется как "Full Changelog: compare/v..." — пустышка. WordPress в модалке
 * «Детали обновления» показывает то, что вернёт plugins_api → sections.changelog.
 * Берём полноценный changelog из readme.txt в корне репо по конкретному тегу —
 * он всегда актуален и пишется одним местом (при каждом релизе).
 *
 * @param string $tag_name Например 'v2.16.1-beta' или '2.16.1-beta'
 * @param int    $max_versions Сколько последних версий показывать (0 = все)
 * @return string HTML-готовый changelog или пустая строка при ошибке
 */
function yd_github_fetch_readme_changelog( $tag_name, $max_versions = 5 ) {
    $cache_key = 'yd_github_readme_changelog';
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) && isset( $cached['tag'] ) && $cached['tag'] === $tag_name ) {
        return (string) $cached['html'];
    }

    // raw.githubusercontent.com отдаёт файлы из репо по тегу/ветке быстрее и без лимитов API.
    $tag = $tag_name;
    $raw_url = sprintf(
        'https://raw.githubusercontent.com/al-nemirov/yandex-delivery-woocommerce/%s/readme.txt',
        rawurlencode( $tag )
    );

    $response = wp_remote_get( $raw_url, array(
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'YandexDostavka-WordPress-Updater/1.0',
        ),
    ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        // Фолбэк — ветка main (если тег недоступен по какой-то причине).
        $raw_url  = 'https://raw.githubusercontent.com/al-nemirov/yandex-delivery-woocommerce/main/readme.txt';
        $response = wp_remote_get( $raw_url, array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'YandexDostavka-WordPress-Updater/1.0' ) ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }
    }

    $body = wp_remote_retrieve_body( $response );
    if ( ! is_string( $body ) || $body === '' ) {
        return '';
    }

    $html = yd_parse_readme_changelog_to_html( $body, $max_versions );
    set_transient( $cache_key, array( 'tag' => $tag_name, 'html' => $html ), 6 * HOUR_IN_SECONDS );
    return $html;
}

/**
 * Парсер секции == Changelog == формата WordPress.org readme.txt → HTML.
 *
 * Поддерживает:
 *   = 2.16.1-beta =     → <h4>2.16.1-beta</h4>
 *   *жирный*            → <strong>
 *   `код`               → <code>
 *   **строго жирный**   → <strong>
 *   * пункт             → <li>
 *   1. пункт            → <li> внутри <ol>
 *   пустая строка       → разделитель
 *
 * Не используем полноценный markdown (не тянем зависимости), нам хватает подмножества.
 *
 * @param string $readme_body Сырое содержимое readme.txt
 * @param int    $max_versions Ограничение на число секций (0 = без лимита)
 * @return string
 */
function yd_parse_readme_changelog_to_html( $readme_body, $max_versions = 5 ) {
    // Вырезаем секцию Changelog от == Changelog == до конца файла или след. ==...==
    if ( ! preg_match( '/==\s*Changelog\s*==\s*(.+)$/is', $readme_body, $m ) ) {
        return '';
    }
    $chunk = $m[1];
    // Обрезаем на следующей секции верхнего уровня (== ... ==) — хотя changelog обычно последний.
    if ( preg_match( '/^(.+?)^==\s*[^=]+?\s*==/sm', $chunk, $m2 ) ) {
        $chunk = $m2[1];
    }

    // Разбиваем на блоки по маркеру = X.Y.Z =
    $parts = preg_split( '/^=\s*([^=\n]+?)\s*=\s*$/m', $chunk, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
    if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
        return '<pre>' . esc_html( trim( $chunk ) ) . '</pre>';
    }

    $html   = '';
    $shown  = 0;
    // Первый элемент — текст до первой версии (игнорируем), далее [version, body, version, body, …].
    $offset = ( count( $parts ) % 2 === 1 ) ? 1 : 0;
    for ( $i = $offset; $i < count( $parts ); $i += 2 ) {
        if ( $max_versions > 0 && $shown >= $max_versions ) { break; }
        $ver  = isset( $parts[ $i ] ) ? trim( $parts[ $i ] ) : '';
        $body = isset( $parts[ $i + 1 ] ) ? trim( $parts[ $i + 1 ] ) : '';
        if ( $ver === '' ) { continue; }

        $html .= '<h4 style="margin:16px 0 6px;">' . esc_html( $ver ) . '</h4>';
        $html .= yd_readme_body_to_html( $body );
        $shown++;
    }

    return $html ?: '<pre>' . esc_html( trim( $chunk ) ) . '</pre>';
}

/**
 * Конвертирует тело одной changelog-секции (между = X.Y.Z =) в HTML.
 * Поддерживает **жирный**, *курсив*, `код`, пункты со звёздочкой и нумерованные.
 */
function yd_readme_body_to_html( $body ) {
    $lines = preg_split( "/\r?\n/", $body );
    $html  = '';
    $in_ul = false;
    $in_ol = false;
    $para  = array();

    $flush_para = function () use ( &$para, &$html ) {
        if ( ! empty( $para ) ) {
            $text = implode( ' ', $para );
            $html .= '<p>' . yd_readme_inline( $text ) . '</p>';
            $para = array();
        }
    };
    $close_lists = function () use ( &$in_ul, &$in_ol, &$html ) {
        if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
        if ( $in_ol ) { $html .= '</ol>'; $in_ol = false; }
    };

    foreach ( $lines as $raw ) {
        $line = rtrim( $raw );
        if ( $line === '' ) {
            $flush_para();
            $close_lists();
            continue;
        }
        // "* пункт" (или "- пункт")
        if ( preg_match( '/^\s*[\*\-]\s+(.*)$/u', $line, $mm ) ) {
            $flush_para();
            if ( $in_ol ) { $html .= '</ol>'; $in_ol = false; }
            if ( ! $in_ul ) { $html .= '<ul>'; $in_ul = true; }
            $html .= '<li>' . yd_readme_inline( $mm[1] ) . '</li>';
            continue;
        }
        // "1. пункт"
        if ( preg_match( '/^\s*\d+\.\s+(.*)$/u', $line, $mm ) ) {
            $flush_para();
            if ( $in_ul ) { $html .= '</ul>'; $in_ul = false; }
            if ( ! $in_ol ) { $html .= '<ol>'; $in_ol = true; }
            $html .= '<li>' . yd_readme_inline( $mm[1] ) . '</li>';
            continue;
        }
        // Обычная строка — копим в параграф.
        $close_lists();
        $para[] = ltrim( $line );
    }
    $flush_para();
    $close_lists();
    return $html;
}

/**
 * Инлайн-разметка: **bold**, *italic*, `code`. Остальной текст экранируется.
 */
function yd_readme_inline( $text ) {
    $text = esc_html( $text );
    $text = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
    // *курсив* — после ** чтобы не съесть звёздочки.
    $text = preg_replace( '/(^|[^\*])\*([^\*\n]+?)\*(?!\*)/u', '$1<em>$2</em>', $text );
    $text = preg_replace( '/`([^`]+?)`/u', '<code>$1</code>', $text );
    return $text;
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

    $download_url = $release['zipball_url'];
    if ( ! empty( $release['assets'] ) ) {
        foreach ( $release['assets'] as $asset ) {
            if ( substr( $asset['name'], -4 ) === '.zip' ) {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }
    }

    // Changelog берём из readme.txt на GitHub (секция == Changelog ==) — там
    // подробный список за всю историю. Body релиза обычно пустышка вида
    // "Full Changelog: compare/...". Если readme.txt недоступен — фолбэк на body.
    $changelog_html = yd_github_fetch_readme_changelog( $release['tag_name'], 5 );
    if ( $changelog_html === '' ) {
        $changelog_html = nl2br( esc_html( $release['body'] ?? '' ) );
    }

    return (object) array(
        'name'          => $plugin_data['Name'],
        'slug'          => 'yandex-dostavka',
        'version'       => ltrim( $release['tag_name'], 'v' ),
        'author'        => $plugin_data['Author'],
        'homepage'      => $plugin_data['PluginURI'],
        'download_link' => $download_url,
        'sections'      => array(
            'description' => $plugin_data['Description'],
            'changelog'   => $changelog_html,
        ),
        'requires'      => '5.0',
        'requires_php'  => '7.2',
        'last_updated'  => $release['published_at'] ?? '',
    );
}

/**
 * After GitHub zip install, rename extracted folder to match plugin slug
 * and re-activate the plugin.
 */
function yd_github_post_install( $response, $hook_extra, $result ) {
    if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( __FILE__ ) ) {
        return $result;
    }
    global $wp_filesystem;
    $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( plugin_basename( __FILE__ ) );
    $wp_filesystem->move( $result['destination'], $plugin_dir );
    $result['destination'] = $plugin_dir;
    activate_plugin( plugin_basename( __FILE__ ) );
    return $result;
}

// Yandex Delivery API client
require_once __DIR__ . '/includes/class-yandex-delivery-api.php';
require_once __DIR__ . '/includes/yd-api-console.php';

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
 * @return int    Оценочная стоимость в копейках (только целые рубли → кратно 100).
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
    return yd_money_rub_to_api_kopecks( $sum );
}

/**
 * Сумма для API ЯД: сначала рубли округляем до целого, затем в копейки (без дробных копеек в запросе).
 *
 * @param float $amount_rub
 * @return int
 */
function yd_money_rub_to_api_kopecks( $amount_rub ) {
    return (int) ( round( (float) $amount_rub ) * 100 );
}

/**
 * Уже копейки — привести к целым рублям в терминах API (кратно 100).
 *
 * @param int|float|string $kopecks
 * @return int
 */
function yd_kopecks_whole_rubles_api( $kopecks ) {
    return (int) ( round( (float) $kopecks / 100 ) * 100 );
}

/**
 * Перед create_request: все денежные поля в теле — целые рубли.
 *
 * @param array $request_data
 * @return array
 */
function yd_request_data_round_money_for_api( array $request_data ) {
    if ( isset( $request_data['total_assessed_price'] ) ) {
        $request_data['total_assessed_price'] = yd_kopecks_whole_rubles_api( $request_data['total_assessed_price'] );
    }
    if ( ! empty( $request_data['billing_info'] ) && is_array( $request_data['billing_info'] ) ) {
        foreach ( array( 'delivery_cost', 'full_items_price' ) as $key ) {
            if ( array_key_exists( $key, $request_data['billing_info'] ) ) {
                $request_data['billing_info'][ $key ] = yd_kopecks_whole_rubles_api( $request_data['billing_info'][ $key ] );
            }
        }
    }
    if ( ! empty( $request_data['items'] ) && is_array( $request_data['items'] ) ) {
        foreach ( $request_data['items'] as $idx => $it ) {
            if ( ! is_array( $it ) ) {
                continue;
            }
            if ( isset( $it['price'] ) ) {
                $request_data['items'][ $idx ]['price'] = (int) round( (float) $it['price'] );
            }
            if ( ! empty( $it['billing_details'] ) && is_array( $it['billing_details'] ) ) {
                foreach ( array( 'unit_price', 'assessed_unit_price' ) as $ik ) {
                    if ( isset( $it['billing_details'][ $ik ] ) ) {
                        $request_data['items'][ $idx ]['billing_details'][ $ik ] = yd_kopecks_whole_rubles_api( $it['billing_details'][ $ik ] );
                    }
                }
            }
        }
    }
    return $request_data;
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

/**
 * Схлопывает однотипные позиции внутри одного грузоместа после поштучной укладки.
 *
 * @param array $chunks Список [ 'product' => WC_Product, 'variation_id' => int, 'quantity' => int ]
 * @return array
 */
function yd_merge_package_product_chunks( $chunks ) {
    $acc = array();
    foreach ( $chunks as $ch ) {
        $pid = (int) $ch['product']->get_id();
        $vid = (int) $ch['variation_id'];
        $key = $pid . '_' . $vid;
        if ( ! isset( $acc[ $key ] ) ) {
            $acc[ $key ] = array(
                'product'      => $ch['product'],
                'variation_id' => $vid,
                'quantity'     => 0,
            );
        }
        $acc[ $key ]['quantity'] += (int) $ch['quantity'];
    }
    return array_values( $acc );
}

/**
 * Дробит набор товаров на несколько грузомест по максимальному весу одного места (г).
 * Жадная укладка поштучно; одна единица тяжелее лимита — false.
 *
 * @param array $packageProducts См. yd_calculate_package_dims
 * @param int   $max_place_weight_g
 * @param float $default_weight
 * @return array<int, array>|false Сегменты (каждый — массив для yd_calculate_package_dims)
 */
function yd_split_package_products_by_place_weight( $packageProducts, $max_place_weight_g, $default_weight ) {
    if ( empty( $packageProducts ) ) {
        return array();
    }
    if ( $max_place_weight_g <= 0 ) {
        return array( $packageProducts );
    }

    $weight_c = yd_unit_coefficients()['weight_c'];
    $lines    = array();

    foreach ( $packageProducts as $entry ) {
        $qty = max( 0, (int) $entry['quantity'] );
        if ( $qty < 1 ) {
            continue;
        }
        $vid = isset( $entry['variation_id'] ) ? (int) $entry['variation_id'] : 0;
        $raw = (float) bxbGetWeight( $entry['product'], $vid ) * $weight_c;
        $uw  = $raw <= 0 ? (int) ceil( (float) $default_weight ) : (int) ceil( $raw );
        if ( $uw > $max_place_weight_g ) {
            return false;
        }
        $lines[] = array(
            'product'      => $entry['product'],
            'variation_id' => $vid,
            'quantity'     => $qty,
            'unit_w'       => $uw,
        );
    }

    if ( empty( $lines ) ) {
        return array();
    }

    $places_out = array();
    $cur        = array();
    $cur_w      = 0;

    foreach ( $lines as $line ) {
        for ( $n = 0; $n < (int) $line['quantity']; $n++ ) {
            if ( $cur_w + $line['unit_w'] > $max_place_weight_g && ! empty( $cur ) ) {
                $places_out[] = yd_merge_package_product_chunks( $cur );
                $cur          = array();
                $cur_w        = 0;
            }
            $lc = count( $cur );
            if ( $lc > 0 ) {
                $last = &$cur[ $lc - 1 ];
                if ( (int) $last['product']->get_id() === (int) $line['product']->get_id()
                    && (int) $last['variation_id'] === (int) $line['variation_id'] ) {
                    $last['quantity']++;
                    $cur_w += $line['unit_w'];
                    continue;
                }
            }
            $cur[] = array(
                'product'      => $line['product'],
                'variation_id' => $line['variation_id'],
                'quantity'     => 1,
            );
            $cur_w += $line['unit_w'];
        }
    }

    if ( ! empty( $cur ) ) {
        $places_out[] = yd_merge_package_product_chunks( $cur );
    }

    return $places_out;
}

/**
 * Строит массив places для pricing-calculator / контроль габаритов по сегментам.
 *
 * @param array $segments Список сегментов (массивов товаров)
 * @param array $dimOpts  Опции для yd_calculate_package_dims
 * @return array{places: array, total_weight: int}|false
 */
function yd_build_places_payload_from_segments( $segments, $dimOpts ) {
    $places       = array();
    $total_weight = 0;
    foreach ( $segments as $seg ) {
        $dims = yd_calculate_package_dims( $seg, $dimOpts );
        if ( $dims === false ) {
            return false;
        }
        $w = (int) $dims['weight'];
        $total_weight += $w;
        $places[] = array(
            'physical_dims' => array(
                'weight_gross' => $w,
                'dx'           => max( 1, (int) $dims['depth'] ),
                'dy'           => max( 1, (int) $dims['width'] ),
                'dz'           => max( 1, (int) $dims['height'] ),
            ),
        );
    }
    if ( empty( $places ) ) {
        return false;
    }
    return array(
        'places'       => $places,
        'total_weight' => $total_weight,
    );
}

/**
 * Находит позицию заказа WC по товару из сегмента (совпадение ID вариации/продукта).
 *
 * @param WC_Order_Item_Product[] $orderItems
 * @return WC_Order_Item_Product|null
 */
function yd_find_order_item_for_segment_product( $orderItems, $product ) {
    if ( ! $product ) {
        return null;
    }
    $pid = (int) $product->get_id();
    foreach ( $orderItems as $oi ) {
        if ( ! $oi instanceof WC_Order_Item_Product ) {
            continue;
        }
        $op = $oi->get_product();
        if ( $op && (int) $op->get_id() === $pid ) {
            return $oi;
        }
    }
    return null;
}

/**
 * Оплата при получении для заявки в Яндекс Доставке и для текста в метабоксе.
 * Доставка с суффиксом *_after ИЛИ стандартный наложенный платёж WooCommerce (cod).
 *
 * @param WC_Order $order
 * @param string   $yd_method_id method_id из bxbGetShippingData (yd_self, yd_self_after, …).
 */
function yd_order_is_pay_on_receipt_for_yd_api( $order, $yd_method_id ) {
    if ( ! $order instanceof WC_Order ) {
        return false;
    }
    if ( is_string( $yd_method_id ) && strpos( $yd_method_id, '_after' ) !== false ) {
        return true;
    }
    return $order->get_payment_method() === 'cod';
}

/**
 * Имя и фамилия получателя для API ЯД (recipient_info).
 * ПВЗ: в shipping_ часто пустая фамилия — тогда берём billing; если всё в одном поле — делим по пробелу.
 *
 * @param WC_Order $order
 * @return array{first_name:string,last_name:string}
 */
function yd_order_recipient_names_for_yandex( WC_Order $order ) {
    $ship_fn = trim( (string) $order->get_shipping_first_name() );
    $ship_ln = trim( (string) $order->get_shipping_last_name() );
    $bill_fn = trim( (string) $order->get_billing_first_name() );
    $bill_ln = trim( (string) $order->get_billing_last_name() );

    // Доставка в тот же ПВЗ: WC часто не дублирует фамилию в shipping — берём из плательщика.
    if ( $ship_ln === '' && $bill_ln !== '' ) {
        $fn = $bill_fn !== '' ? $bill_fn : $ship_fn;
        $ln = $bill_ln;
    } else {
        $fn = $ship_fn !== '' ? $ship_fn : $bill_fn;
        $ln = $ship_ln !== '' ? $ship_ln : $bill_ln;
    }

    // Одно поле «Имя Фамилия» / «Тест Тестович».
    if ( $ln === '' && $fn !== '' ) {
        $parts = preg_split( '/\s+/u', $fn, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $parts ) >= 2 ) {
            $ln = (string) array_pop( $parts );
            $fn = implode( ' ', $parts );
        }
    }

    if ( $fn === '' && $ln === '' ) {
        $full = trim( (string) $order->get_formatted_billing_full_name() );
        if ( $full !== '' ) {
            $parts = preg_split( '/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY );
            if ( count( $parts ) >= 2 ) {
                $ln = (string) array_pop( $parts );
                $fn = implode( ' ', $parts );
            } else {
                $fn = $full;
            }
        }
    }

    // Если фамилия пустая — лучше продублировать имя, чем печатать «—» на этикетке/акте.
    if ( $ln === '' ) {
        $default_ln = $fn !== '' ? $fn : '.';
        $ln = (string) apply_filters( 'yd_recipient_last_name_fallback', $default_ln, $order, $fn );
    }
    if ( $fn === '' ) {
        $default_fn = $ln !== '' ? $ln : '.';
        $fn = (string) apply_filters( 'yd_recipient_first_name_fallback', $default_fn, $order, $ln );
    }

    return apply_filters(
        'yd_recipient_names_for_yandex',
        array(
            'first_name' => $fn,
            'last_name'  => $ln,
        ),
        $order
    );
}

/**
 * Нормализация телефона под пример из документации ЯД (Contact.phone): +79529999999.
 *
 * @param string $phone
 * @return string
 */
function yd_normalize_phone_for_yandex_api( $phone ) {
    $raw = trim( (string) $phone );
    if ( $raw === '' ) {
        return $raw;
    }
    $digits = preg_replace( '/\D+/', '', $raw );
    if ( $digits === '' ) {
        return $raw;
    }
    // 8 9XX … (РФ) → 7 9XX …
    if ( strlen( $digits ) === 11 && $digits[0] === '8' ) {
        $digits = '7' . substr( $digits, 1 );
    }
    // 10 цифр, моб. РФ с 9
    if ( strlen( $digits ) === 10 && isset( $digits[0] ) && $digits[0] === '9' ) {
        $digits = '7' . $digits;
    }
    if ( strlen( $digits ) === 11 && $digits[0] === '7' ) {
        return '+' . $digits;
    }
    if ( strpos( $raw, '+' ) === 0 ) {
        return '+' . $digits;
    }
    return $raw;
}

/**
 * Поля recipient_info для POST /api/b2b/platform/request/create.
 *
 * По официальной схеме Contact (см. документацию «Доставка в другой день», метод request/create):
 * first_name — имя, last_name — фамилия, patronymic — отчество, phone, email.
 * Нельзя подменять фамилию строкой «Имя Фамилия» в last_name — это не соответствует контракту API.
 *
 * Обходной путь (дублировать полное ФИО в оба поля): только явно:
 * add_filter( 'yd_recipient_duplicate_full_name_both_fields_workaround', '__return_true' );
 *
 * @param WC_Order $order
 * @param array    $names Результат yd_order_recipient_names_for_yandex()
 * @param string   $phone
 * @param string   $email
 * @return array
 * @link https://yandex.ru/support/delivery-profile/ru/api/other-day/ref/3.-Osnovnye-zaprosy/apib2bplatformrequestcreate-post
 */
function yd_build_recipient_info_for_create_request( WC_Order $order, array $names, $phone, $email ) {
    $fn = trim( (string) ( $names['first_name'] ?? '' ) );
    $ln = trim( (string) ( $names['last_name'] ?? '' ) );

    $phone_n = yd_normalize_phone_for_yandex_api( $phone );
    $phone_n = apply_filters( 'yd_recipient_phone_for_yandex', $phone_n, $order, $phone );

    $patronymic = apply_filters( 'yd_recipient_patronymic_for_yandex', '', $order, $names );
    if ( ! is_string( $patronymic ) ) {
        $patronymic = '';
    }

    $email_s = sanitize_email( (string) $email );

    $info = array(
        'first_name' => $fn,
        'last_name'  => $ln,
        'patronymic' => $patronymic,
        'phone'      => $phone_n,
        'email'      => $email_s,
    );

    // ЯД на актах/этикетках показывает ТОЛЬКО first_name.
    // Чтобы фамилия была видна — кладём полное ФИО в first_name.
    // Отключить: add_filter('yd_recipient_put_fullname_in_first_name', '__return_false');
    if ( apply_filters( 'yd_recipient_put_fullname_in_first_name', true, $order, $names ) && $fn !== '' && $ln !== '' && $ln !== '—' ) {
        $info['first_name'] = trim( $fn . ' ' . $ln );  // Имя Фамилия (как в ЛК ЯД)
    }

    return apply_filters( 'yd_recipient_info_for_yandex', $info, $order, $names );
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
        'Яндекс Доставка',
        'Яндекс Доставка',
        'manage_woocommerce',
        'yandex-dostavka-settings',
        'yd_settings_page'
    );
}

function yd_settings_page() {
    // Сохранение настроек
    if ( isset( $_POST['yd_save_settings'] ) ) {
        check_admin_referer( 'yd_settings' );
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
    $is_test    = get_option( 'yd_test_mode', 'no' ) === 'yes';
    $nonce_test = wp_create_nonce( 'yd_toggle_test_mode' );
    ?>
    <div class="wrap">
        <h1>Яндекс Доставка — Настройки</h1>

        <form method="post" style="max-width:700px;margin-top:20px;">
            <?php wp_nonce_field( 'yd_settings' ); ?>

            <!-- API-ключи -->
            <h2>API-ключи</h2>
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
                            Подсказки города и адреса на чекауте.
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
                            Карта выбора ПВЗ на чекауте.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="yd_save_settings" class="button-primary" value="Сохранить настройки" />
            </p>
        </form>

        <!-- Тестовый режим -->
        <hr style="margin:30px 0;">
        <h2>Тестовый режим</h2>
        <div style="max-width:700px;padding:16px 20px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
            <p style="margin-top:0;color:#666;">
                Когда включён — неавторизованные пользователи видят заглушку на чекауте и корзине.
                Авторизованные работают как обычно.
            </p>
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo $is_test ? '#dc3232' : '#46b450'; ?>;"></span>
                <strong><?php echo $is_test ? 'Тестовый режим ВКЛЮЧЁН' : 'Тестовый режим выключен'; ?></strong>
                <button type="button" class="button <?php echo $is_test ? 'button-secondary' : ''; ?>"
                        id="yd-toggle-test-mode" data-nonce="<?php echo esc_attr( $nonce_test ); ?>" style="margin-left:12px;">
                    <?php echo $is_test ? 'Выключить' : 'Включить'; ?>
                </button>
            </div>
        </div>

        <!-- Ссылки -->
        <hr style="margin:30px 0;">
        <h2>Методы доставки</h2>
        <p>Настройки OAuth-токена, склада, наценок и автоотправки — в параметрах каждого метода доставки:</p>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" class="button">WooCommerce &rarr; Доставка &rarr; Зоны доставки</a></p>

    </div>
    <script>document.addEventListener('DOMContentLoaded',function(){var btn=document.getElementById('yd-toggle-test-mode');if(btn){btn.addEventListener('click',function(){btn.disabled=true;btn.textContent='...';fetch(ajaxurl+'?action=yd_toggle_test_mode&_wpnonce='+btn.dataset.nonce).then(function(r){return r.json()}).then(function(){location.reload()}).catch(function(){btn.disabled=false;btn.textContent='Ошибка'})})}});</script>
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
        foreach ( yd_all_method_ids() as $mid ) {
            $s = get_option( 'woocommerce_' . $mid . '_settings' );
            if ( is_array( $s ) && ! empty( $s['key'] ) ) {
                yd_add_reception_points( $s['key'] );
                error_log( '[YD] Re-fetched reception points with lat/lng for key from ' . $mid );
                break;
            }
        }
    }

    // Добавить колонку cash_allowed (флаг поддержки наложенного платежа в ПВЗ)
    if ( yd_is_reception_points_table_exist() && ! get_option( 'yd_reception_points_v3' ) ) {
        global $wpdb;
        $t    = $wpdb->prefix . 'yd_reception_points';
        $cols = $wpdb->get_col( "DESCRIBE `{$t}`", 0 );
        if ( ! in_array( 'cash_allowed', $cols ) ) {
            // DEFAULT 1 = считаем ПВЗ принимает COD, пока не перезагрузим данные
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN cash_allowed TINYINT(1) NOT NULL DEFAULT 1" );
            error_log( '[YD] Migration v3: added cash_allowed column' );
        }
        update_option( 'yd_reception_points_v3', 1 );
    }

    // Добавить колонку payment_methods (JSON со списком способов оплаты в ПВЗ: postpay / card_on_receipt)
    if ( yd_is_reception_points_table_exist() && ! get_option( 'yd_reception_points_v4' ) ) {
        global $wpdb;
        $t    = $wpdb->prefix . 'yd_reception_points';
        $cols = $wpdb->get_col( "DESCRIBE `{$t}`", 0 );
        if ( ! in_array( 'payment_methods', $cols ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN payment_methods VARCHAR(128) NOT NULL DEFAULT ''" );
            error_log( '[YD] Migration v4: added payment_methods column' );
        }
        update_option( 'yd_reception_points_v4', 1 );
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

/**
 * Единый список всех базовых ID методов доставки Яндекса (без суффикса :instance_id).
 * Используется для: поиска токена в настройках, группировки методов в UI,
 * миграций, фильтров. BETA методы тоже сюда включены, чтобы одного токена хватало.
 *
 * Фильтруется через 'yd_all_method_ids' — кастомный subclass можно зарегистрировать
 * извне и автоматически получить справочник ПВЗ, статусы, акты и т.п.
 *
 * @return string[]
 */
function yd_all_method_ids() {
    static $cached = null;
    if ( $cached !== null ) {
        return $cached;
    }
    $cached = (array) apply_filters( 'yd_all_method_ids', array(
        'yd_self',
        'yd_self_after',
        'yd_courier',
        'yd_courier_after',
        'yd_express',   // BETA
        'yd_same_day',  // BETA
    ) );
    return $cached;
}

/**
 * Только ПВЗ-методы (самовывоз покупателем). Курьерские тарифы сюда НЕ входят.
 * Используется для ветвлений типа «скрыть наличные для ПВЗ без COD», «показать
 * кнопку выбора ПВЗ», маппинга адреса доставки в код ПВЗ и т.д.
 *
 * @return string[]
 */
function yd_pvz_method_ids() {
    return (array) apply_filters( 'yd_pvz_method_ids', array( 'yd_self', 'yd_self_after' ) );
}

/**
 * Флаг для wp-admin: BETA-тариф упал (скорее всего, не подключён в ЛК Яндекс.Доставки).
 * Показываем баннер в админке, пока админ не кликнет "Скрыть" — чтобы настройка не пропала
 * мимо глаз и метод не висел скрытым неделями.
 */
function yd_flag_beta_tariff_failure( $method_id, $tariff, $err_msg ) {
    $flags = get_option( 'yd_beta_tariff_failures', array() );
    if ( ! is_array( $flags ) ) { $flags = array(); }
    $flags[ $method_id ] = array(
        'tariff'  => (string) $tariff,
        'error'   => (string) $err_msg,
        'time'    => time(),
    );
    update_option( 'yd_beta_tariff_failures', $flags, false );
}

// Баннер в админке: BETA-тариф не работает.
add_action( 'admin_notices', 'yd_beta_tariff_admin_notice' );
function yd_beta_tariff_admin_notice() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
    $flags = get_option( 'yd_beta_tariff_failures', array() );
    if ( empty( $flags ) || ! is_array( $flags ) ) { return; }
    foreach ( $flags as $mid => $info ) {
        if ( ! is_array( $info ) ) { continue; }
        $tariff = isset( $info['tariff'] ) ? $info['tariff'] : '?';
        $err    = isset( $info['error'] ) ? $info['error'] : '';
        $when   = ! empty( $info['time'] ) ? date( 'd.m.Y H:i', (int) $info['time'] ) : '';
        printf(
            '<div class="notice notice-warning is-dismissible"><p>'
            . '<strong>Яндекс Доставка (BETA):</strong> метод <code>%s</code> скрыт на чекауте — '
            . 'тариф <code>%s</code> вернул ошибку API: <em>%s</em> (%s).<br>'
            . 'Проверьте, подключён ли тариф в '
            . '<a href="https://yandex.ru/delivery/" target="_blank" rel="noopener">ЛК Яндекс.Доставки</a>. '
            . 'После исправления — <a href="%s">сбросить флаг</a>.'
            . '</p></div>',
            esc_html( $mid ),
            esc_html( $tariff ),
            esc_html( $err ),
            esc_html( $when ),
            esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yd_reset_beta_failure&mid=' . rawurlencode( $mid ) ), 'yd_reset_beta_failure' ) )
        );
    }
}

add_action( 'admin_post_yd_reset_beta_failure', 'yd_reset_beta_failure_handler' );
function yd_reset_beta_failure_handler() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden' ); }
    check_admin_referer( 'yd_reset_beta_failure' );
    $mid = isset( $_GET['mid'] ) ? sanitize_text_field( wp_unslash( $_GET['mid'] ) ) : '';
    $flags = get_option( 'yd_beta_tariff_failures', array() );
    if ( is_array( $flags ) && isset( $flags[ $mid ] ) ) {
        unset( $flags[ $mid ] );
        update_option( 'yd_beta_tariff_failures', $flags, false );
        delete_transient( 'yd_beta_fail_' . $mid );
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url() );
    exit;
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

/**
 * Самолечение крона: если по какой-то причине событие yd_update_data_event
 * не зарегистрировано (например, плагин обновили через FTP без деактивации),
 * регистрируем его на каждом init. Дёшево и идемпотентно.
 * Плюс: если таблица ПВЗ пустая или устарела > 3 дней, один раз в час
 * делаем принудительный рефреш — чтобы не ждать следующий cron tick.
 */
add_action( 'init', 'yd_ensure_crons_registered', 20 );
function yd_ensure_crons_registered() {
    if ( ! wp_next_scheduled( 'yd_update_data_event' ) ) {
        wp_schedule_event( time() + 60, 'twicedaily', 'yd_update_data_event' );
    }
    if ( ! wp_next_scheduled( 'yd_status_sync_event' ) ) {
        wp_schedule_event( time() + 120, 'every_two_hours', 'yd_status_sync_event' );
    }
}

/**
 * Ленивый рефреш справочника ПВЗ при запросе виджета — если данные устарели,
 * пересобираем до того, как отдать пользователю неполный список. Throttling
 * через transient (1 час), чтобы не дёргать API на каждый чих.
 */
add_action( 'init', 'yd_maybe_refresh_reception_points_stale', 30 );
function yd_maybe_refresh_reception_points_stale() {
    if ( wp_doing_cron() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        return;
    }
    if ( ! function_exists( 'yd_is_reception_points_table_exist' ) ) {
        return;
    }
    if ( get_transient( 'yd_pvz_refresh_throttle' ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'yd_reception_points';
    if ( ! yd_is_reception_points_table_exist() ) {
        return;
    }
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    // Если пусто — рефреш немедленно. Иначе — раз в сутки, ставим метку last_refresh.
    $last_refresh = (int) get_option( 'yd_pvz_last_refresh', 0 );
    $stale_after  = (int) apply_filters( 'yd_pvz_stale_after_seconds', 3 * DAY_IN_SECONDS );
    $is_stale     = $count === 0 || ( time() - $last_refresh ) > $stale_after;
    if ( ! $is_stale ) {
        return;
    }
    // Троттлинг — один рефреш на час максимум.
    set_transient( 'yd_pvz_refresh_throttle', 1, HOUR_IN_SECONDS );

    // Ищем любой активный токен.
    $token = '';
    if ( class_exists( 'WC_Shipping_Zones' ) ) {
        foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
            if ( empty( $zone['shipping_methods'] ) ) continue;
            foreach ( $zone['shipping_methods'] as $m ) {
                if ( strpos( $m->id, 'yd' ) !== false && $m->is_enabled() ) {
                    $k = $m->get_option( 'key' );
                    if ( ! empty( $k ) ) { $token = $k; break 2; }
                }
            }
        }
    }
    if ( ! $token ) return;

    if ( function_exists( 'yd_log' ) ) {
        yd_log( sprintf( '[YD PVZ] auto-refresh: count=%d, last_refresh=%s, stale_after=%ds',
            $count, $last_refresh ? date( 'Y-m-d H:i:s', $last_refresh ) : 'never', $stale_after ) );
    }
    yd_add_reception_points( $token );
    if ( function_exists( 'yd_add_cities' ) ) {
        yd_add_cities( $token );
    }
    update_option( 'yd_pvz_last_refresh', time(), false );
}

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

// ─── Нормализация ответов API ЯД (спецификация 2025+) ─────────────────────

/**
 * События из GET /request/history: в документации — state_history (не history).
 *
 * @param array $api_response
 * @return array<int, array>
 */
function yd_yandex_history_events( $api_response ) {
    if ( ! is_array( $api_response ) ) {
        return array();
    }
    if ( ! empty( $api_response['state_history'] ) && is_array( $api_response['state_history'] ) ) {
        return $api_response['state_history'];
    }
    if ( ! empty( $api_response['history'] ) && is_array( $api_response['history'] ) ) {
        return $api_response['history'];
    }
    return array();
}

/**
 * Дата/время из RequestState (timestamp unix или timestamp_utc ISO).
 *
 * @param array $event
 * @return string Формат d.m.Y H:i или пустая строка
 */
function yd_format_yandex_event_time( $event ) {
    if ( ! is_array( $event ) ) {
        return '';
    }
    if ( ! empty( $event['timestamp_utc'] ) ) {
        $t = strtotime( $event['timestamp_utc'] );
        return $t ? date( 'd.m.Y H:i', $t ) : '';
    }
    if ( isset( $event['timestamp'] ) && is_numeric( $event['timestamp'] ) ) {
        return date( 'd.m.Y H:i', (int) $event['timestamp'] );
    }
    if ( ! empty( $event['updated_ts'] ) ) {
        $t = strtotime( $event['updated_ts'] );
        return $t ? date( 'd.m.Y H:i', $t ) : '';
    }
    return '';
}

/**
 * Сохранить из GET /request/info: статус, номер у оператора, ссылка трекинга для клиента, код ПВЗ.
 *
 * @param WC_Order $order
 * @param array    $info Ответ API request/info
 */
function yd_persist_yandex_request_info_extras( $order, $info ) {
    if ( ! $order instanceof WC_Order || ! is_array( $info ) ) {
        return;
    }

    if ( ! empty( $info['courier_order_id'] ) ) {
        $order->update_meta_data( 'yd_courier_order_id', sanitize_text_field( $info['courier_order_id'] ) );
    }
    if ( ! empty( $info['sharing_url'] ) ) {
        $order->update_meta_data( 'yd_sharing_url', esc_url_raw( $info['sharing_url'] ) );
    }
    if ( ! empty( $info['self_pickup_node_code'] ) && is_array( $info['self_pickup_node_code'] ) ) {
        $code = isset( $info['self_pickup_node_code']['code'] ) ? (string) $info['self_pickup_node_code']['code'] : '';
        if ( $code !== '' ) {
            $order->update_meta_data( 'yd_pickup_code', $code );
        }
    }
}

/**
 * Сохраняет PDF из ответа API в медиатеку (uploads).
 *
 * @param string $pdf_binary
 * @param string $preferred_filename Имя из Content-Disposition (может быть пустым)
 * @param string $fallback_basename  Без расширения или с .pdf
 * @return array Как wp_upload_bits (url, file, error)
 */
function yd_store_pdf_to_uploads( $pdf_binary, $preferred_filename, $fallback_basename ) {
    $fname = is_string( $preferred_filename ) ? sanitize_file_name( $preferred_filename ) : '';
    if ( $fname === '' || substr( strtolower( $fname ), -4 ) !== '.pdf' ) {
        $fname = sanitize_file_name( (string) $fallback_basename );
    }
    if ( substr( strtolower( $fname ), -4 ) !== '.pdf' ) {
        $fname .= '.pdf';
    }
    return wp_upload_bits( $fname, null, $pdf_binary );
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
        'status'     => array( 'processing', 'on-hold', 'pending' ),
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

        // Не дёргаем API для заказов, которые уже в терминальном статусе на стороне ЯД
        // (CANCELLED, SHOP_CANCELLED, DELIVERED, RETURNED…). Экономит лимит и не флудит лог.
        $lastCode = (string) $order->get_meta( 'yd_last_status_code' );
        if ( yd_is_terminal_status_code( $lastCode ) ) {
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
        $statusCode = isset( $result['state']['status'] ) ? $result['state']['status'] : '';
        $statusDate = yd_format_yandex_event_time( $result['state'] );
        if ( $statusDate === '' ) {
            $statusDate = current_time( 'd.m.Y H:i' );
        }
        $storedStatus = $order->get_meta( 'yd_last_status' );

        yd_persist_yandex_request_info_extras( $order, $result );

        // Сохраняем полную историю (ключ API: state_history)
        $history = $yd_client->get_request_history( $trackingNumber );
        $hist_events = yd_yandex_history_events( $history );
        if ( ! is_wp_error( $history ) && ! empty( $hist_events ) ) {
            $order->update_meta_data( 'yd_tracking_history', wp_json_encode( $hist_events ) );
        }
        $order->update_meta_data( 'yd_last_sync', current_time( 'd.m.Y H:i:s' ) );

        // Если статус изменился — записываем
        if ( $storedStatus !== $statusName ) {
            $order->update_meta_data( 'yd_last_status', $statusName );
            $order->update_meta_data( 'yd_last_status_code', $statusCode );
            $order->update_meta_data( 'yd_last_status_date', $statusDate );
            $order->add_order_note(
                sprintf( 'Яндекс Доставка: %s (%s)', $statusName, $statusDate ),
                false,
                true
            );

            // Авто-завершение при вручении (если включено в настройках)
            $autoComplete = $shippingData['object']->get_option( 'auto_complete_on_delivery', '0' );
            if ( $autoComplete === '1' && yd_is_delivered_status( $statusName ) ) {
                $order->update_status( 'completed', 'Заказ автоматически завершён: посылка вручена получателю.' );
            } else {
                $order->save();
            }
        } else {
            $order->save();
        }
    }
}

/**
 * Ручная синхронизация статуса одного заказа (кнопка в мета-боксе).
 */
function yd_sync_single_order_status( $postId ) {
    $order = wc_get_order( $postId );
    if ( ! $order ) {
        return;
    }
    $trackingNumber = $order->get_meta( 'yd_tracking_number' );
    if ( empty( $trackingNumber ) ) {
        return;
    }
    $shippingData = bxbGetShippingData( $order );
    if ( ! isset( $shippingData['object'] ) ) {
        return;
    }
    $key = $shippingData['object']->get_option( 'key' );
    if ( empty( $key ) ) {
        return;
    }

    $yd_client = new Yandex_Delivery_API( $key );

    // Сохраняем историю (state_history)
    $history = $yd_client->get_request_history( $trackingNumber );
    $hist_events = yd_yandex_history_events( $history );
    if ( ! is_wp_error( $history ) && ! empty( $hist_events ) ) {
        $order->update_meta_data( 'yd_tracking_history', wp_json_encode( $hist_events ) );
    }

    // Сохраняем текущий статус
    $result = $yd_client->get_request_info( $trackingNumber );
    if ( ! is_wp_error( $result ) && isset( $result['state'] ) ) {
        $statusName = isset( $result['state']['description'] ) ? $result['state']['description'] : ( isset( $result['state']['status'] ) ? $result['state']['status'] : '' );
        $statusCode = isset( $result['state']['status'] ) ? $result['state']['status'] : '';
        $statusDate = yd_format_yandex_event_time( $result['state'] );
        if ( $statusDate === '' ) {
            $statusDate = current_time( 'd.m.Y H:i' );
        }
        $storedStatus = $order->get_meta( 'yd_last_status' );

        yd_persist_yandex_request_info_extras( $order, $result );

        $order->update_meta_data( 'yd_last_status', $statusName );
        $order->update_meta_data( 'yd_last_status_code', $statusCode );
        $order->update_meta_data( 'yd_last_status_date', $statusDate );

        if ( $storedStatus !== $statusName ) {
            $order->add_order_note(
                sprintf( 'Яндекс Доставка: %s (%s)', $statusName, $statusDate ),
                false,
                true
            );
        }
    }

    $order->update_meta_data( 'yd_last_sync', current_time( 'd.m.Y H:i:s' ) );
    $order->save();
}

/**
 * Логирование с учётом debug_mode.
 * Пишет в error_log и (опционально) в мету заказа.
 *
 * @param string       $message  Сообщение
 * @param int|null     $order_id ID заказа (для сохранения лога в мету)
 * @param string|null  $key      API-ключ метода (для проверки debug_mode). Если null — логирует всегда.
 */
function yd_log( $message, $order_id = null, $key = null ) {
    $debug_enabled = false;

    if ( $order_id ) {
        $debug_enabled = yd_is_debug( $order_id );
    }

    // Пишем в отдельный лог-файл wp-content/yd-debug.log (как у YooKassa)
    if ( $debug_enabled || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
        $log_file = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/yd-debug.log' : ABSPATH . 'wp-content/yd-debug.log';
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
        @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    }

    // Сохраняем в мету заказа для отображения в мета-боксе
    if ( $order_id && $debug_enabled ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $log = $order->get_meta( 'yd_debug_log' );
            $entries = $log ? json_decode( $log, true ) : array();
            if ( ! is_array( $entries ) ) {
                $entries = array();
            }
            $entries[] = current_time( 'Y-m-d H:i:s' ) . ' — ' . $message;
            if ( count( $entries ) > 50 ) {
                $entries = array_slice( $entries, -50 );
            }
            $order->update_meta_data( 'yd_debug_log', wp_json_encode( $entries ) );
            $order->save();
        }
    }
}

/**
 * Пишет в yd-debug.log всегда (без проверки debug_mode).
 * Для критических диагностических сообщений.
 */
function yd_log_always( $message ) {
    $log_file = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/yd-debug.log' : ABSPATH . 'wp-content/yd-debug.log';
    $timestamp = current_time( 'Y-m-d H:i:s' );
    $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
    @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
}

/**
 * Проверяет, включён ли debug_mode для заказа.
 */
function yd_is_debug( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;
    }
    $shippingData = bxbGetShippingData( $order );
    if ( ! isset( $shippingData['object'] ) ) {
        return false;
    }
    return $shippingData['object']->get_option( 'debug_mode' ) === '1';
}

/**
 * Терминальные коды статусов Яндекс.Доставки — заказ закрыт, тянуть get_request_info больше не нужно.
 *
 * @param string $statusCode  Значение из state.status (yd_last_status_code)
 * @return bool
 */
function yd_is_terminal_status_code( $statusCode ) {
    if ( ! is_string( $statusCode ) || $statusCode === '' ) {
        return false;
    }
    $terminal = apply_filters( 'yd_terminal_status_codes', array(
        'DELIVERED',
        'DELIVERED_FINISH',
        'CANCELLED',
        'SHOP_CANCELLED',
        'RETURNED',
        'RETURNED_FINISH',
        'RETURNED_TO_SHOP',
        'RETURNED_DELIVERED',
        'FAILED',
    ) );
    return in_array( strtoupper( $statusCode ), $terminal, true );
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
            /**
             * Явный код тарифа для pricing-calculator ('self_pickup' | 'time_interval' | 'express' | 'same_day').
             * Если null — используется старая логика: self_type ? 'self_pickup' : 'time_interval'.
             * Задаётся в конструкторе дочернего класса.
             */
            public $tariff = null;

            /**
             * BETA-методы (Express / Same-day): если Яндекс API вернёт ошибку —
             * метод СКРЫВАЕТСЯ на чекауте вместо fallback на fixed_cost.
             * Так мы не продадим клиенту «Экспресс за 350 ₽», если подписка
             * в ЛК Яндекс.Доставки не подключена.
             */
            public $is_beta = false;

            public function __construct( $instance_id = 0 )
            {
                parent::__construct();
                $this->instance_id = absint( $instance_id );
                $this->supports    = array(
                    'shipping-zones',
                    'instance-settings'
                );

                // BETA-предупреждение прямо в настройках метода (WC → Shipping zones → [method])
                if ( $this->is_beta ) {
                    $this->method_description = sprintf(
                        '<div style="padding:12px 14px;background:#fffbe6;border-left:4px solid #f0b429;border-radius:4px;margin:8px 0;">'
                        . '<strong>⚠ BETA-метод.</strong> Тариф <code>%s</code> требует подключения в '
                        . '<a href="https://yandex.ru/delivery/" target="_blank" rel="noopener">ЛК Яндекс.Доставки</a>.<br>'
                        . 'Если тариф не подключён — метод автоматически <strong>скрывается</strong> на чекауте '
                        . '(не показываем клиенту цену 350 ₽ по fallback). '
                        . 'В wp-admin появится баннер с ошибкой API — не пропустите.'
                        . '</div>',
                        esc_html( (string) $this->tariff )
                    );
                }

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
                        'title'             => 'Макс. вес одного грузоместа (г)',
                        'description'       => 'Тяжёлый заказ автоматически делится на несколько посылок в Яндекс Доставке. Одна единица товара тяжелее этого значения — доставка недоступна. 0 = без дробления по весу.',
                        'desc_tip'          => true,
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
                    'size_attribute' => array(
                        'title'       => 'Атрибут размера для веса',
                        'description' => 'Slug атрибута WooCommerce (например, pa_razmer). Если у товара есть этот атрибут и значение совпадает с маппингом ниже — вес берётся из маппинга.',
                        'desc_tip'    => true,
                        'type'        => 'text',
                        'default'     => 'pa_razmer',
                    ),
                    'size_weight_map' => array(
                        'title'       => 'Маппинг размер → вес (кг)',
                        'description' => 'Формат: S=2, M=5, L=12, XL=15, XXL=20, XXL+=30, КГТ=200. Значения в кг.',
                        'type'        => 'textarea',
                        'default'     => 'S=2, M=5, L=12, XL=15, XXL=20, XXL+=30, КГТ=200',
                        'css'         => 'width:100%; height:60px;',
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
                    'pvz_non_cod_gateways'                => array(
                        'title'             => 'Оплата в ПВЗ без наложенного платежа',
                        'type'              => 'multiselect',
                        'class'             => 'wc-enhanced-select',
                        'css'               => 'width: 400px;',
                        'default'           => '',
                        'description'       => 'Какие способы оплаты доступны покупателю, если выбранный ПВЗ не принимает оплату при получении. Если не заполнено — ограничений нет.',
                        'options'           => $this->get_available_payment_methods(),
                        'desc_tip'          => true,
                        'custom_attributes' => array(
                            'data-placeholder' => 'Выберите разрешённые способы оплаты'
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
                    'debug_mode'                          => array(
                        'title'    => 'Режим отладки',
                        'desc_tip' => 'Включает подробное логирование всех API-запросов и ответов в yd-debug.log и мета-бокс заказа. Выключайте на продакшене!',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'default'  => '0',
                        'options'  => [
                            '0' => 'Выключено',
                            '1' => 'Включено',
                        ]
                    ),
                    'show_reset_button'                   => array(
                        'title'    => 'Кнопка сброса статуса',
                        'desc_tip' => 'Показывать кнопку «Сброс статуса» в мета-боксе заказа. Используйте для тестов — сбрасывает данные ЯД и позволяет отправить заказ заново.',
                        'type'     => 'select',
                        'class'    => 'wc-enhanced-select',
                        'default'  => '0',
                        'options'  => [
                            '0' => 'Скрыта',
                            '1' => 'Показана',
                        ]
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
                $dimOpts = array(
                    'default_weight'           => $defaultWeight,
                    'default_height'           => $defaultHeight,
                    'default_depth'            => $defaultDepth,
                    'default_width'            => $defaultWidth,
                    'apply_default_dimensions' => $applyDefaultDimensions,
                    'min_weight'               => $minWeight,
                    'max_height'               => $maxHeight,
                    'max_depth'                => $maxDepth,
                    'max_width'                => $maxWidth,
                );

                $maxPlaceW = (int) $maxWeight;
                if ( (int) $applyDefaultDimensions === 2 || $maxPlaceW <= 0 ) {
                    $segments = array( $cartProducts );
                } else {
                    $segments = yd_split_package_products_by_place_weight( $cartProducts, $maxPlaceW, $defaultWeight );
                    if ( $segments === false ) {
                        error_log( sprintf( '[YD] Item heavier than max place weight %dg — method %s hidden', $maxPlaceW, $this->id ) );
                        return false;
                    }
                }

                $segments = array_values( array_filter( $segments, function ( $seg ) {
                    return is_array( $seg ) && ! empty( $seg );
                } ) );
                if ( empty( $segments ) ) {
                    return false;
                }

                $payload = yd_build_places_payload_from_segments( $segments, $dimOpts );
                if ( $payload === false ) {
                    return false;
                }

                if ( $payload['total_weight'] < $minWeight ) {
                    error_log( sprintf( '[YD] Package weight %dg is below min_weight %dg for method %s — rate hidden', $payload['total_weight'], $minWeight, $this->id ) );
                    return false;
                }

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
                            $pvzAddr = sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) );
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

                    // Тариф: self_pickup для ПВЗ, time_interval для курьера (по умолчанию).
                    // Дочерний класс может явно задать $this->tariff = 'express' | 'same_day' и т.п.
                    $tariff = $this->tariff ?: ( $this->self_type ? 'self_pickup' : 'time_interval' );

                    // P0 Fix: единая формула оценочной стоимости (копейки)
                    $qualifiedCartItems = array();
                    foreach ( WC()->cart->get_cart() as $ci ) {
                        $p = $ci['data'];
                        if ( ! $p->is_virtual() && ! $p->is_downloadable() ) {
                            $qualifiedCartItems[] = $ci;
                        }
                    }
                    $assessedPrice = yd_assessed_price_minor_units( $qualifiedCartItems, 'cart' );

                    // Кэш в рамках одного запроса — yd_self и yd_self_after
                    // не дублируют API-вызов если параметры одинаковые
                    $cacheKey = md5( wp_json_encode( array(
                        $sourceStationId ?: $sourceAddress,
                        $destinationStationId ?: $destinationAddress,
                        $tariff,
                        (int) $payload['total_weight'],
                        $assessedPrice,
                        $payload['places'],
                    ) ) );

                    // Для BETA-методов: если мы недавно получили ошибку "тариф не подключён"
                    // — не стучимся в API и сразу прячем метод. Кэш в transient на 1 час.
                    $beta_fail_key = 'yd_beta_fail_' . $this->id;
                    if ( $this->is_beta && get_transient( $beta_fail_key ) ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[YD BETA] ' . $this->id . ': hidden (recent API failure cached)' );
                        }
                        return false;
                    }

                    if ( ! isset( $GLOBALS['yd_pricing_cache'][ $cacheKey ] ) ) {
                        // Debug (only with WP_DEBUG, addresses masked for PII)
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[YD] === CALC REQUEST (' . $this->id . ') ===' );
                            error_log( '[YD] source_station=' . ( $sourceStationId ?: 'N/A' ) );
                            error_log( '[YD] dest_station=' . ( $destinationStationId ?: 'N/A' ) );
                            error_log( '[YD] tariff=' . $tariff . ', total_weight=' . (int) $payload['total_weight'] . 'g, places=' . count( $payload['places'] ) );
                            error_log( '[YD] assessed_price=' . $assessedPrice . ' kopecks (' . round( $assessedPrice / 100, 2 ) . ' RUB)' );
                        }

                        $GLOBALS['yd_pricing_cache'][ $cacheKey ] = $yd_client->calculate_price(
                            $sourceAddress,
                            $destinationAddress,
                            $tariff,
                            (int) $payload['total_weight'],
                            $assessedPrice,
                            array(),
                            $sourceStationId,
                            $destinationStationId,
                            $payload['places']
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
                        // Успех — сбрасываем флаг "тариф не работает".
                        if ( $this->is_beta ) {
                            delete_transient( $beta_fail_key );
                        }
                    } else {
                        $apiErr = is_wp_error( $result ) ? $result->get_error_message() : 'pricing_total not found';

                        // BETA-защита: ЛЮБАЯ ошибка API = метод скрыт.
                        // Никакого fixed_cost fallback — клиент не должен получить «Экспресс за 350 ₽»,
                        // если подписка в ЛК Яндекс.Доставки не подключена.
                        if ( $this->is_beta ) {
                            set_transient( $beta_fail_key, $apiErr, HOUR_IN_SECONDS );
                            error_log( sprintf(
                                '[YD BETA] %s: hidden on checkout — tariff "%s" API error: %s. '
                                . 'Проверьте подключение тарифа в ЛК Яндекс.Доставки.',
                                $this->id, $tariff, $apiErr
                            ) );
                            // Админу — одноразовое уведомление в wp-admin.
                            if ( function_exists( 'yd_flag_beta_tariff_failure' ) ) {
                                yd_flag_beta_tariff_failure( $this->id, $tariff, $apiErr );
                            }
                            return false;
                        }

                        $fixedCost = (float) $this->get_option( 'fixed_cost', 350 );
                        $costReceived = $fixedCost;
                        // Fallback всегда логируем — это ошибка, не debug
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
                    // Цена доставки для покупателя — целые рубли (API ЯД часто даёт 192,36; в магазине показываем 192 ₽).
                    $cost_decimals = (int) apply_filters( 'yd_shipping_cost_round_decimals', 0, $this, $package );
                    $finalCost     = round( $finalCost, $cost_decimals );

                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[YD] pricing: base=' . $costReceived . ' +add=' . $this->addcost . ' +markup=' . $markupPercent . '% = ' . $finalCost );
                    }

                    $this->add_rate( [
                        'id'    => $this->get_rate_id(),
                        'label' => ( $this->title . $deliveryPeriod ),
                        'cost'  => $finalCost,
                    ] );

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

        // ─── BETA: Экспресс-курьер (доставка за 1-2 часа) ────────────────────────
        // Тариф Яндекса: 'express'. В ЛК Яндекс.Доставки должна быть включена подписка
        // «Экспресс-доставка» — если не включена, pricing-calculator вернёт ошибку,
        // и метод будет СКРЫТ на чекауте (is_beta=true блокирует fixed_cost fallback).
        class WC_YD_Express_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_express';
                $this->method_title         = 'Яндекс Доставка — Экспресс (BETA)';
                $this->instance_form_fields = array();
                $this->self_type            = false;
                $this->payment_after        = false;
                $this->tariff               = 'express';
                $this->is_beta              = true;
                parent::__construct( $instance_id );
                $this->key = $this->get_option( 'key' );
            }
        }

        // ─── BETA: Доставка в день заказа (same-day) ────────────────────────────
        // Тариф Яндекса: 'same_day'. Аналогично — требует подключения в ЛК.
        class WC_YD_SameDay_Method extends WC_YD_Parent_Method {
            public function __construct( $instance_id = 0 )
            {
                $this->id                   = 'yd_same_day';
                $this->method_title         = 'Яндекс Доставка — В день заказа (BETA)';
                $this->instance_form_fields = array();
                $this->self_type            = false;
                $this->payment_after        = false;
                $this->tariff               = 'same_day';
                $this->is_beta              = true;
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

    /**
     * Парсит строку маппинга размер→вес.
     *
     * @param string $map_string Строка вида "S=2, M=5, L=12"
     * @return array Ассоциативный массив ['s' => 2000, 'm' => 5000, ...] (значения в граммах)
     */
    function yd_parse_size_weight_map( $map_string ) {
        $map = array();
        if ( empty( $map_string ) ) {
            return $map;
        }
        $pairs = preg_split( '/\s*,\s*/', trim( $map_string ) );
        foreach ( $pairs as $pair ) {
            $parts = explode( '=', $pair, 2 );
            if ( count( $parts ) === 2 ) {
                $size   = mb_strtolower( trim( $parts[0] ) );
                $weight = (float) trim( $parts[1] );
                if ( $size !== '' && $weight > 0 ) {
                    $map[ $size ] = (int) round( $weight * 1000 ); // кг → граммы
                }
            }
        }
        return $map;
    }

    /**
     * Получает вес товара по атрибуту размера из маппинга.
     *
     * @param WC_Product $product Товар.
     * @param int        $id      Variation ID.
     * @param string     $size_attr Slug атрибута размера.
     * @param array      $weight_map Маппинг размер→вес (в граммах).
     * @return float|false Вес в единицах WC (кг) или false если не найден.
     */
    function yd_get_weight_by_size( $product, $id, $size_attr, $weight_map ) {
        if ( empty( $size_attr ) || empty( $weight_map ) ) {
            return false;
        }

        $size_value = '';

        // Для вариации — берём атрибут из вариации
        if ( $id > 0 ) {
            $variation = wc_get_product( $id );
            if ( $variation && is_a( $variation, 'WC_Product_Variation' ) ) {
                $attr_key   = 'attribute_' . $size_attr;
                $size_value = $variation->get_attribute( $size_attr );
                if ( empty( $size_value ) ) {
                    $size_value = get_post_meta( $id, $attr_key, true );
                }
            }
        }

        // Для простого товара или если вариация не дала значения
        if ( empty( $size_value ) && $product ) {
            $size_value = $product->get_attribute( $size_attr );
        }

        if ( empty( $size_value ) ) {
            return false;
        }

        $size_key = mb_strtolower( trim( $size_value ) );
        if ( isset( $weight_map[ $size_key ] ) ) {
            // Возвращаем в кг (WC единицы), т.к. weight_map хранит граммы
            $wc_unit = strtolower( get_option( 'woocommerce_weight_unit', 'kg' ) );
            if ( $wc_unit === 'g' ) {
                return (float) $weight_map[ $size_key ];
            }
            return (float) $weight_map[ $size_key ] / 1000;
        }

        return false;
    }

    /**
     * Получить настройки size_attribute и size_weight_map из первого экземпляра
     * метода доставки YD в зонах WooCommerce. Результат кэшируется за запрос.
     *
     * @return array ['size_attr' => string, 'weight_map' => array]
     */
    function yd_get_size_weight_settings() {
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }
        $cached = array( 'size_attr' => '', 'weight_map' => array() );

        $yd_method_ids = yd_all_method_ids();

        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = array( 'zone_id' => 0 ); // Зона «Остальные»
        foreach ( $zones as $zone_data ) {
            $zone    = WC_Shipping_Zones::get_zone( $zone_data['zone_id'] );
            $methods = $zone->get_shipping_methods( true ); // только включённые
            foreach ( $methods as $method ) {
                if ( in_array( $method->id, $yd_method_ids, true ) ) {
                    $size_attr  = $method->get_option( 'size_attribute', 'pa_razmer' );
                    $map_string = $method->get_option( 'size_weight_map', '' );
                    $weight_map = yd_parse_size_weight_map( $map_string );
                    if ( ! empty( $weight_map ) ) {
                        $cached = array( 'size_attr' => $size_attr, 'weight_map' => $weight_map );
                        return $cached;
                    }
                }
            }
        }
        return $cached;
    }

    function bxbGetWeight( $product, $id = 0 )
    {
        if ( ! $product ) {
            return 0;
        }

        // Попытка определить вес по маппингу размер→вес
        $settings = yd_get_size_weight_settings();
        if ( ! empty( $settings['weight_map'] ) ) {
            $size_weight = yd_get_weight_by_size( $product, $id, $settings['size_attr'], $settings['weight_map'] );
            if ( $size_weight !== false ) {
                return $size_weight;
            }
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

        $weight = (float) $product->get_weight();
        if ( $weight <= 0 ) {
            error_log( sprintf(
                '[YD] Товар #%d «%s»: вес не указан и маппинг размер→вес не найден. Будет использован вес по умолчанию.',
                $product->get_id(),
                $product->get_name()
            ) );
        }
        return $weight;
    }

    // bxbGetUrl() removed — dead code, never called

    add_action( 'woocommerce_shipping_init', 'yd_shipping_method_init' );

    function yd_shipping_method( $methods )
    {
        $methods['yd_self']          = 'WC_YD_Self_Method';
        $methods['yd_courier']       = 'WC_YD_Courier_Method';
        $methods['yd_self_after']    = 'WC_YD_SelfAfter_Method';
        $methods['yd_courier_after'] = 'WC_YD_CourierAfter_Method';
        // BETA — должны быть подключены в ЛК Яндекс.Доставки.
        $methods['yd_express']       = 'WC_YD_Express_Method';
        $methods['yd_same_day']      = 'WC_YD_SameDay_Method';

        return $methods;
    }

    add_filter( 'woocommerce_shipping_methods', 'yd_shipping_method' );

    /**
     * Фильтрует доступные способы оплаты на чекауте, если выбранный ПВЗ
     * не принимает наложенный платёж (yd_pvz_cash_allowed=0).
     * Список разрешённых способов берётся из настройки pvz_non_cod_gateways
     * метода доставки yd_self / yd_self_after.
     */
    add_filter( 'woocommerce_available_payment_gateways', function( $gateways ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return $gateways;
        }

        // Проверяем куки — ПВЗ выбран и не принимает наличные
        $cash_allowed = isset( $_COOKIE['yd_pvz_cash_allowed'] )
            ? sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_cash_allowed'] ) )
            : '1';
        if ( $cash_allowed !== '0' ) {
            return $gateways;
        }

        // Убеждаемся что выбран ПВЗ-метод Яндекс Доставки
        $chosen = array();
        if ( function_exists( 'WC' ) && WC()->session ) {
            $chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        }
        $pvz_methods = yd_pvz_method_ids();
        $is_yd_pvz   = false;
        foreach ( $chosen as $method_id ) {
            $base = explode( ':', $method_id )[0];
            if ( in_array( $base, $pvz_methods, true ) ) {
                $is_yd_pvz = true;
                break;
            }
        }
        if ( ! $is_yd_pvz ) {
            return $gateways;
        }

        // Ищем настройку pvz_non_cod_gateways в первом активном yd_self/yd_self_after методе
        $allowed_ids = array();
        $zones       = WC_Shipping_Zones::get_zones();
        $zones[]     = array( 'id' => 0 ); // «Остальные регионы»
        foreach ( $zones as $zone_data ) {
            if ( ! empty( $allowed_ids ) ) {
                break;
            }
            $zone = new WC_Shipping_Zone( $zone_data['id'] );
            foreach ( $zone->get_shipping_methods( true ) as $method ) {
                if ( in_array( $method->id, $pvz_methods, true ) ) {
                    $setting = $method->get_option( 'pvz_non_cod_gateways', array() );
                    if ( ! empty( $setting ) && is_array( $setting ) ) {
                        $allowed_ids = $setting;
                        break;
                    }
                }
            }
        }

        // Если настройка пустая — убираем только стандартный COD-шлюз WooCommerce
        if ( empty( $allowed_ids ) ) {
            unset( $gateways['cod'] );
            return $gateways;
        }

        // Оставляем только явно разрешённые шлюзы
        foreach ( array_keys( $gateways ) as $gateway_id ) {
            if ( ! in_array( $gateway_id, $allowed_ids, true ) ) {
                unset( $gateways[ $gateway_id ] );
            }
        }

        return $gateways;
    } );

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
        $history      = $yd_client->get_request_history( $data['track'] );
        $historyItems = yd_yandex_history_events( $history );

        if ( ! is_wp_error( $history ) && ! empty( $historyItems ) ) {
            $html = '<div><ul class="order_notes" style="max-height: 300px; overflow-y: auto;">';
            $items = array_reverse( $historyItems );
            foreach ( $items as $idx => $status ) {
                $statusName = isset( $status['description'] ) ? $status['description'] : ( isset( $status['status'] ) ? $status['status'] : '' );
                $statusDate = yd_format_yandex_event_time( $status );
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
            $statusDate = yd_format_yandex_event_time( $info['state'] );
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
                echo '<p><b><u>Возникла ошибка</u></b>: ' . esc_html( $errorText ) . '</p>';
                echo '<p><input type="submit" class="add_note button" name="yd_create_parsel" value="Попробовать снова" title="Повторно отправить заказ в Яндекс Доставку. Предыдущая попытка завершилась ошибкой."></p>';

                // Дебаг-лог при ошибке
                if ( yd_is_debug( $order_id ) ) {
                    $debugLog = $order->get_meta( 'yd_debug_log' );
                    if ( $debugLog ) {
                        $entries = json_decode( $debugLog, true );
                        if ( is_array( $entries ) && ! empty( $entries ) ) {
                            echo '<details style="margin-top:10px;"><summary style="cursor:pointer;color:#c00;font-size:12px;">&#128736; Debug log (' . count( $entries ) . ')</summary>';
                            echo '<div style="max-height:200px;overflow-y:auto;font-size:11px;background:#fff5f5;padding:6px;border:1px solid #fdd;border-radius:4px;margin-top:4px;">';
                            foreach ( array_reverse( $entries ) as $entry ) {
                                echo '<div style="border-bottom:1px solid #fee;padding:2px 0;">' . esc_html( $entry ) . '</div>';
                            }
                            echo '</div></details>';
                        }
                    }
                }

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

                echo '<p><span style="display: inline-block;">Номер отправления (request_id):</span>';
                echo '<span style="margin-left: 10px"><b>' . esc_html( $trackingNumber ) . '</b></span>';

                $yd_courier_tr = $order->get_meta( 'yd_courier_order_id' );
                if ( $yd_courier_tr ) {
                    echo '<br><small style="color:#444;">Трек оператора: <strong>' . esc_html( $yd_courier_tr ) . '</strong></small>';
                }
                $yd_pv = $order->get_meta( 'yd_pickup_code' );
                if ( $yd_pv ) {
                    echo '<br><small style="color:#444;">Код выдачи в ПВЗ: <strong>' . esc_html( $yd_pv ) . '</strong></small>';
                }
                $yd_share = $order->get_meta( 'yd_sharing_url' );
                if ( $yd_share ) {
                    echo '<p style="margin:8px 0;"><a class="button" href="' . esc_url( $yd_share ) . '" target="_blank" rel="noopener noreferrer">Отслеживание для получателя (Яндекс)</a></p>';
                }

                // Способ оплаты
                $paymentTitle  = $order->get_payment_method_title();
                if ( yd_order_is_pay_on_receipt_for_yd_api( $order, $shippingData['method_id'] ) ) {
                    echo '<p style="color:#b26200;margin:4px 0;">&#128176; Оплата при получении — <strong>' . wp_kses_post( wc_price( $order->get_total(), array( 'decimals' => 0 ) ) ) . '</strong></p>';
                } else {
                    echo '<p style="color:#059377;margin:4px 0;">&#9989; ' . esc_html( $paymentTitle ) . '</p>';
                }

                if ( ! empty( $labelLink ) ) {
                    echo '<p><a class="button" href="' . esc_url( $labelLink ) . '" target="_blank" title="Скачать PDF-этикетку для наклейки на посылку. Откроется в новой вкладке.">Скачать этикетку &#10067;</a></p>';
                } else {
                    echo '<p><input type="submit" class="button" name="yd_download_label" value="Скачать этикетку" title="Запросить PDF-этикетку из Яндекс Доставки (generate-labels)."></p>';
                }

                if ( isset( $actLink ) && $actLink !== '' ) {
                    echo '<p><a class="button" href="' . esc_url( $actLink ) . '" target="_blank" title="Скачать акт приёма-передачи. Нужен при сдаче посылки в пункт приёма.">Скачать акт &#10067;</a></p>';
                }

                if ( empty( $actLink ) ) {
                    echo '<p><input type="submit" class="add_note button" name="yd_create_act" value="Сформировать акт" title="Сгенерировать акт приёма-передачи в Яндекс Доставке. Нужен при сдаче посылки в пункт приёма."></p>';
                }

                // Сброс (настройка show_reset_button)
                if ( $shippingData['object']->get_option( 'show_reset_button' ) === '1' ) {
                    echo '<hr style="margin:8px 0;">';
                    echo '<p><input type="submit" class="button" name="yd_resend_parsel" value="Пересоздать заявку" onclick="return confirm(\'Пересоздать заявку в ЯД? Старый заказ нужно отменить в ЛК Яндекс Доставки вручную.\');" style="color:#999;border-color:#ccc;font-size:11px;" title="Удалить данные текущей заявки и создать новую в Яндекс Доставке. Старую заявку отмените в ЛК ЯД."></p>';
                }

                // Показываем сохранённый трекинг из меты (без лишних API-запросов)
                $lastStatus = $order->get_meta( 'yd_last_status' );
                $lastStatusDate = $order->get_meta( 'yd_last_status_date' );
                $lastSync = $order->get_meta( 'yd_last_sync' );
                $savedHistory = $order->get_meta( 'yd_tracking_history' );

                if ( $savedHistory ) {
                    $historyItems = json_decode( $savedHistory, true );
                    if ( is_array( $historyItems ) && ! empty( $historyItems ) ) {
                        echo '<p><strong>История доставки:</strong></p>';
                        echo '<div><ul class="order_notes" style="max-height: 300px; overflow-y: auto;">';
                        $items = array_reverse( $historyItems );
                        foreach ( $items as $idx => $status ) {
                            $sName = isset( $status['description'] ) ? $status['description'] : ( isset( $status['status'] ) ? $status['status'] : '' );
                            $sDate = yd_format_yandex_event_time( $status );
                            $noteClass = ( $idx === 0 ) ? 'note system-note' : 'note';
                            echo '<li class="' . $noteClass . '"><div class="note_content"><p>' . esc_html( $sName ) . '</p></div><p class="meta"><abbr class="exact-date">' . esc_html( $sDate ) . '</abbr></p></li>';
                        }
                        echo '</ul></div>';
                    }
                } elseif ( $lastStatus ) {
                    echo '<p><strong>Статус:</strong> ' . esc_html( $lastStatus ) . '</p>';
                    if ( $lastStatusDate ) {
                        echo '<p><small>' . esc_html( $lastStatusDate ) . '</small></p>';
                    }
                } else {
                    echo '<p style="color:#999;">Нажмите «Обновить статус» для получения данных из ЯД.</p>';
                }

                if ( $lastSync ) {
                    echo '<p><small style="color:#999;">Синхронизация: ' . esc_html( $lastSync ) . '</small></p>';
                }
                echo '<p><input type="submit" class="button" name="yd_refresh_status" value="Обновить статус" title="Запросить актуальный статус доставки из API Яндекс Доставки. Автоматически обновляется каждые 2 часа."></p>';

                // Дебаг-лог (если включён)
                if ( yd_is_debug( $order_id ) ) {
                    $debugLog = $order->get_meta( 'yd_debug_log' );
                    if ( $debugLog ) {
                        $entries = json_decode( $debugLog, true );
                        if ( is_array( $entries ) && ! empty( $entries ) ) {
                            echo '<details style="margin-top:10px;"><summary style="cursor:pointer;color:#666;font-size:12px;">&#128736; Debug log (' . count( $entries ) . ')</summary>';
                            echo '<div style="max-height:200px;overflow-y:auto;font-size:11px;background:#f9f9f9;padding:6px;border:1px solid #ddd;border-radius:4px;margin-top:4px;">';
                            foreach ( array_reverse( $entries ) as $entry ) {
                                echo '<div style="border-bottom:1px solid #eee;padding:2px 0;">' . esc_html( $entry ) . '</div>';
                            }
                            echo '</div></details>';
                        }
                    }
                }
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
                // Информация об оплате
                $paymentMethod = $order->get_payment_method();
                $paymentTitle  = $order->get_payment_method_title();
                $orderTotal    = $order->get_total();
                $shippingCost  = $shippingData['cost'];

                echo '<hr style="margin:8px 0;">';
                echo '<p><strong>Оплата:</strong> ' . esc_html( $paymentTitle ?: $paymentMethod ) . '</p>';
                if ( yd_order_is_pay_on_receipt_for_yd_api( $order, $shippingData['method_id'] ) ) {
                    echo '<p style="color:#b26200;">&#128176; Оплата при получении</p>';
                    echo '<p>Сумма к оплате клиентом: <strong>' . wp_kses_post( wc_price( $orderTotal, array( 'decimals' => 0 ) ) ) . '</strong></p>';
                    echo '<p><small>Товары: ' . wp_kses_post( wc_price( $orderTotal - $shippingCost, array( 'decimals' => 0 ) ) ) . ' + Доставка: ' . wp_kses_post( wc_price( $shippingCost, array( 'decimals' => 0 ) ) ) . '</small></p>';
                } else {
                    echo '<p style="color:#059377;">&#9989; Предоплата (' . wp_kses_post( wc_price( $orderTotal, array( 'decimals' => 0 ) ) ) . ')</p>';
                }
                echo '<hr style="margin:8px 0;">';

                echo '<p style="font-size:12px;color:#666;">Нажмите кнопку чтобы зарегистрировать заявку в Яндекс Доставке. После этого принесите посылку в пункт приёма.</p>';
                echo '<p><input type="submit" class="add_note button" name="yd_create_parsel" value="Отправить в ЯД" title="Создать заявку на доставку в Яндекс Доставке. Заказ будет сразу зарегистрирован, посылку нужно будет принести в пункт приёма."></p>';
            }
        }
    }

    /**
     * Проверяет nonce при сохранении заказа.
     * Поддерживает как CPT (update-post_ID), так и HPOS (update-order_ID).
     */
    function yd_verify_order_nonce( $order_id ) {
        if ( ! isset( $_POST['_wpnonce'] ) ) {
            return false;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
        // CPT-формат
        if ( wp_verify_nonce( $nonce, 'update-post_' . $order_id ) ) {
            return true;
        }
        // HPOS-формат
        if ( wp_verify_nonce( $nonce, 'update-order_' . $order_id ) ) {
            return true;
        }
        // WC generic
        if ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
            return true;
        }
        return false;
    }

    function yd_meta_tracking_code( $postId, $post = null )
    {
        if ( is_object( $postId ) && is_a( $postId, 'WC_Order' ) ) {
            $postId = $postId->get_id();
        }
        $postId = absint( $postId );
        if ( ! $postId ) {
            return;
        }

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        // Предотвращаем повторный вызов (хук может сработать дважды)
        static $processed = array();
        $action_key = '';
        if ( isset( $_POST['yd_create_parsel'] ) ) { $action_key = 'create_' . $postId; }
        elseif ( isset( $_POST['yd_resend_parsel'] ) ) { $action_key = 'resend_' . $postId; }
        elseif ( isset( $_POST['yd_reset_order'] ) ) { $action_key = 'reset_' . $postId; }
        elseif ( isset( $_POST['yd_refresh_status'] ) ) { $action_key = 'refresh_' . $postId; }
        elseif ( isset( $_POST['yd_create_act'] ) ) { $action_key = 'act_' . $postId; }
        elseif ( isset( $_POST['yd_download_label'] ) ) { $action_key = 'label_' . $postId; }
        if ( $action_key && isset( $processed[ $action_key ] ) ) {
            return;
        }
        if ( $action_key ) {
            $processed[ $action_key ] = true;
        }

        // Логируем что хук вызван (только при debug_mode, чтобы не засорять лог)
        if ( yd_is_debug( $postId ) ) {
            yd_log_always( 'HOOK fired for order #' . $postId . ' | POST keys: ' . implode( ',', array_keys( $_POST ) ) );
        }

        if ( ! yd_verify_order_nonce( $postId ) ) {
            // Логируем NONCE FAILED только когда админ нажал кнопку (реальная ошибка),
            // а не при каждом сохранении заказа (checkout и т.п.)
            if ( isset( $_POST['yd_create_parsel'] ) ) {
                yd_log_always( 'NONCE FAILED for order #' . $postId );
            }
            return;
        }

        if ( isset( $_POST['yd_create_parsel'] ) ) {
            yd_log_always( 'yd_create_parsel detected for order #' . $postId );
            yd_get_tracking_code( $postId );
        }
        if ( isset( $_POST['yd_resend_parsel'] ) ) {
            yd_log_always( 'yd_resend_parsel detected for order #' . $postId );
            // Очищаем старые данные и создаём заново
            $order = wc_get_order( $postId );
            if ( $order ) {
                $order->delete_meta_data( 'yd_tracking_number' );
                $order->delete_meta_data( 'yd_link' );
                $order->delete_meta_data( 'yd_act_link' );
                $order->delete_meta_data( 'yd_error' );
                $order->delete_meta_data( 'yd_last_status' );
                $order->delete_meta_data( 'yd_last_status_code' );
                $order->delete_meta_data( 'yd_last_status_date' );
                $order->delete_meta_data( 'yd_tracking_history' );
                $order->delete_meta_data( 'yd_last_sync' );
                $order->delete_meta_data( 'yd_debug_log' );
                $order->delete_meta_data( 'yd_courier_order_id' );
                $order->delete_meta_data( 'yd_sharing_url' );
                $order->delete_meta_data( 'yd_pickup_code' );
                // Инкремент счётчика для уникального operator_request_id
                $resend = (int) $order->get_meta( 'yd_resend_count' );
                $order->update_meta_data( 'yd_resend_count', $resend + 1 );
                $order->save();
                yd_get_tracking_code( $postId );
            }
        }
        if ( isset( $_POST['yd_reset_order'] ) ) {
            yd_log_always( 'yd_reset_order detected for order #' . $postId );
            $order = wc_get_order( $postId );
            if ( $order ) {
                $order->delete_meta_data( 'yd_tracking_number' );
                $order->delete_meta_data( 'yd_link' );
                $order->delete_meta_data( 'yd_act_link' );
                $order->delete_meta_data( 'yd_error' );
                $order->delete_meta_data( 'yd_last_status' );
                $order->delete_meta_data( 'yd_last_status_code' );
                $order->delete_meta_data( 'yd_last_status_date' );
                $order->delete_meta_data( 'yd_tracking_history' );
                $order->delete_meta_data( 'yd_last_sync' );
                $order->delete_meta_data( 'yd_debug_log' );
                $order->delete_meta_data( 'yd_courier_order_id' );
                $order->delete_meta_data( 'yd_sharing_url' );
                $order->delete_meta_data( 'yd_pickup_code' );
                $resend = (int) $order->get_meta( 'yd_resend_count' );
                $order->update_meta_data( 'yd_resend_count', $resend + 1 );
                $order->save();
            }
        }
        if ( isset( $_POST['yd_refresh_status'] ) ) {
            yd_sync_single_order_status( $postId );
        }
        if ( isset( $_POST['yd_create_act'] ) ) {
            bxbCreateAct( $postId );
        }
        if ( isset( $_POST['yd_download_label'] ) ) {
            yd_download_label_on_demand( $postId );
        }
    }

    // После сохранения полей заказа из формы (дефолтные обработчики WC — priority 10)
    add_action( 'woocommerce_process_shop_order_meta', 'yd_meta_tracking_code', 50, 2 );
    // HPOS (WC 7+)
    add_action( 'woocommerce_update_order', 'yd_meta_tracking_code', 50, 2 );
    add_action( 'save_post_shop_order', function( $post_id ) { yd_meta_tracking_code( $post_id ); }, 50, 1 );

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
            } elseif ( ! empty( $result['_pdf'] ) ) {
                $up = yd_store_pdf_to_uploads(
                    $result['_pdf'],
                    ! empty( $result['_filename'] ) ? $result['_filename'] : '',
                    'yd-label-act-' . $order->get_id() . '-' . time()
                );
                if ( ! empty( $up['error'] ) ) {
                    $order->update_meta_data( 'yd_error', $up['error'] );
                    $order->save();
                } else {
                    $order->update_meta_data( 'yd_act_link', $up['url'] );
                    $order->delete_meta_data( 'yd_error' );
                    $order->save();
                }
            } else {
                // Акт ПП: POST /request/get-handover-act (ответ обычно PDF)
                $act_result = $yd_client->generate_act( array( 'request_ids' => array( $trackingNumber ) ) );
                if ( ! is_wp_error( $act_result ) && isset( $act_result['url'] ) ) {
                    $order->update_meta_data( 'yd_act_link', $act_result['url'] );
                    $order->delete_meta_data( 'yd_error' );
                    $order->save();
                } elseif ( ! is_wp_error( $act_result ) && ! empty( $act_result['_pdf'] ) ) {
                    $upload = yd_store_pdf_to_uploads(
                        $act_result['_pdf'],
                        ! empty( $act_result['_filename'] ) ? $act_result['_filename'] : '',
                        'yd-handover-act-' . $order->get_id() . '-' . time()
                    );
                    if ( ! empty( $upload['error'] ) ) {
                        $order->update_meta_data( 'yd_error', $upload['error'] );
                        $order->save();
                    } else {
                        $order->update_meta_data( 'yd_act_link', $upload['url'] );
                        $order->delete_meta_data( 'yd_error' );
                        $order->save();
                    }
                } else {
                    $err = is_wp_error( $act_result ) ? $act_result->get_error_message() : 'Не удалось сформировать акт';
                    $order->update_meta_data( 'yd_error', $err );
                    $order->save();
                }
            }
        }
    }

    /**
     * Запрашивает этикетку (generate-labels) по требованию админа.
     */
    function yd_download_label_on_demand( $postId ) {
        $order = wc_get_order( $postId );
        if ( ! $order ) {
            return;
        }
        $shippingData = bxbGetShippingData( $order );
        if ( ! isset( $shippingData['object'] ) ) {
            return;
        }
        $trackingNumber = $order->get_meta( 'yd_tracking_number' );
        if ( empty( $trackingNumber ) ) {
            return;
        }
        $key = $shippingData['object']->get_option( 'key' );
        $yd_client = new Yandex_Delivery_API( $key );
        $labels = $yd_client->generate_labels( array( $trackingNumber ) );

        if ( is_wp_error( $labels ) ) {
            $order->update_meta_data( 'yd_error', $labels->get_error_message() );
            $order->save();
            return;
        }

        $labelUrl = '';
        if ( isset( $labels['url'] ) ) {
            $labelUrl = $labels['url'];
        } elseif ( isset( $labels['label_url'] ) ) {
            $labelUrl = $labels['label_url'];
        } elseif ( ! empty( $labels['_pdf'] ) ) {
            $fn = ! empty( $labels['_filename'] ) ? $labels['_filename'] : '';
            $up = yd_store_pdf_to_uploads( $labels['_pdf'], $fn, 'yd-label-' . $order->get_id() . '-' . time() );
            if ( empty( $up['error'] ) ) {
                $labelUrl = $up['url'];
            } else {
                $order->update_meta_data( 'yd_error', $up['error'] );
                $order->save();
                return;
            }
        }

        if ( ! empty( $labelUrl ) ) {
            $order->update_meta_data( 'yd_link', $labelUrl );
            $order->delete_meta_data( 'yd_error' );
            $order->save();
        } else {
            $order->update_meta_data( 'yd_error', 'Этикетка ещё не готова. Попробуйте через несколько секунд.' );
            $order->save();
        }
    }

    /**
     * Формирует source для API — либо platform_station_id, либо address (не оба).
     */
    /**
     * Формирует source для API create_request.
     * API create использует "platform_station" (не "platform_station_id" как pricing-calculator).
     * Передаётся только одно: station или address.
     */
    /**
     * Формирует source для API create_request (новый формат 2025+).
     * platform_station — объект с platform_id.
     */
    function yd_build_source( $sourceAddress, $pointForParcelName ) {
        $stationId = $pointForParcelName ? getReceptionPointCodeByName( $pointForParcelName ) : '';
        if ( ! empty( $stationId ) ) {
            return array(
                'platform_station' => array( 'platform_id' => $stationId ),
            );
        }
        return array( 'address' => $sourceAddress );
    }

    /**
     * Формирует destination для API create_request (новый формат 2025+).
     * Для ПВЗ: type=platform_station + platform_station.platform_id
     * Для курьера: type=custom_location + custom_location.details
     */
    function yd_build_destination( $isSelfPickup, $destinationAddress, $pvzCode = '', $order = null ) {
        if ( $isSelfPickup && ! empty( $pvzCode ) ) {
            return array(
                'type'             => 'platform_station',
                'platform_station' => array( 'platform_id' => $pvzCode ),
            );
        }
        // Курьерская доставка — custom_location с адресом
        $city = '';
        $street = '';
        if ( $order ) {
            $city = $order->get_shipping_city() ?: $order->get_billing_city();
            $street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
            if ( empty( $street ) ) {
                $street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
            }
        }
        return array(
            'type'            => 'custom_location',
            'custom_location' => array(
                'details' => array(
                    'full_address' => $destinationAddress,
                    'locality'     => $city,
                    'street'       => $street,
                ),
            ),
        );
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

            // Собираем товары, дробим на грузоместа по весу (как на чекауте), считаем габариты каждого места
            $orderItems = $order->get_items( 'line_item' );

            $defaultWeight          = (float) $shippingData['object']->get_option( 'default_weight' );
            $defaultHeight          = (int) $shippingData['object']->get_option( 'default_height' );
            $defaultDepth           = (int) $shippingData['object']->get_option( 'default_depth' );
            $defaultWidth           = (int) $shippingData['object']->get_option( 'default_width' );
            $applyDefaultDimensions = (int) $shippingData['object']->get_option( 'apply_default_dimensions' );
            $minWeight              = (float) $shippingData['object']->get_option( 'min_weight' );
            $maxPlaceW              = (int) $shippingData['object']->get_option( 'max_weight' );

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

            $dimOptsOrder = array(
                'default_weight'           => $defaultWeight,
                'default_height'           => $defaultHeight,
                'default_depth'            => $defaultDepth,
                'default_width'            => $defaultWidth,
                'apply_default_dimensions' => $applyDefaultDimensions,
                'min_weight'               => $minWeight,
            );

            if ( (int) $applyDefaultDimensions === 2 || $maxPlaceW <= 0 ) {
                $segments = array( $packageProducts );
            } else {
                $segments = yd_split_package_products_by_place_weight( $packageProducts, $maxPlaceW, $defaultWeight );
                if ( $segments === false ) {
                    $order->update_meta_data( 'yd_error', 'Позиция тяжелее максимально допустимого веса одного грузоместа (' . $maxPlaceW . ' г). Разбейте заказ или увеличьте лимит в настройках доставки.' );
                    $order->save();
                    return;
                }
            }

            $segments = array_values( array_filter( $segments, function ( $seg ) {
                return is_array( $seg ) && ! empty( $seg );
            } ) );
            if ( empty( $segments ) ) {
                $order->update_meta_data( 'yd_error', 'Нет товаров для отправки в Яндекс Доставку' );
                $order->save();
                return;
            }

            $placesPayload = yd_build_places_payload_from_segments( $segments, $dimOptsOrder );
            if ( $placesPayload === false ) {
                $order->update_meta_data( 'yd_error', 'Превышены лимиты габаритов посылки' );
                $order->save();
                return;
            }

            $orderIdInt = (int) $order->get_id();
            $numSeg     = count( $segments );
            $items_list = array();

            $unitCoeff = yd_unit_coefficients();
            $weightC   = $unitCoeff['weight_c'];
            $dimC      = $unitCoeff['dimension_c'];

            foreach ( $segments as $segIndex => $seg ) {
                $barcode = ( 1 === $numSeg ) ? 'WC' . $orderIdInt : 'WC' . $orderIdInt . '-' . ( $segIndex + 1 );
                foreach ( $seg as $entry ) {
                    $oi = yd_find_order_item_for_segment_product( $orderItems, $entry['product'] );
                    if ( ! $oi ) {
                        continue;
                    }
                    $product = $entry['product'];
                    $sku     = is_callable( array( $product, 'get_sku' ) ) ? $product->get_sku() : '';
                    $id      = (string) ( $sku !== '' ? $sku : $oi['product_id'] );
                    $lineQty = max( 1, (int) $oi->get_quantity() );
                    // Цены в ЯД без копеек в позиции: целые рубли (книги), в billing_details — кратно 100 коп.
                    $lineTotalRub     = ( (float) $oi->get_total() + (float) $oi->get_total_tax() ) / $lineQty;
                    $unitPriceRub     = (int) round( $lineTotalRub );
                    $unitPriceKopecks = $unitPriceRub * 100;

                    // Вес единицы товара в граммах для ЯД
                    $varId       = isset( $entry['variation_id'] ) ? (int) $entry['variation_id'] : 0;
                    $rawWeightG  = (float) bxbGetWeight( $product, $varId ) * $weightC;
                    $itemWeightG = $rawWeightG > 0 ? (int) ceil( $rawWeightG ) : (int) ceil( $defaultWeight );

                    // Габариты единицы товара в см для ЯД (items[].physical_dims)
                    $itemDx = (int) round( (float) $product->get_length() * $dimC );
                    $itemDy = (int) round( (float) $product->get_width()  * $dimC );
                    $itemDz = (int) round( (float) $product->get_height() * $dimC );
                    if ( 1 === $applyDefaultDimensions ) {
                        if ( $itemDx <= 0 ) { $itemDx = (int) $defaultDepth; }
                        if ( $itemDy <= 0 ) { $itemDy = (int) $defaultWidth; }
                        if ( $itemDz <= 0 ) { $itemDz = (int) $defaultHeight; }
                    }

                    $items_list[] = array(
                        'article'         => $id,
                        'name'            => $oi->get_name(),
                        'price'           => $unitPriceRub,
                        'count'           => (int) $entry['quantity'],
                        'place_barcode'   => $barcode,
                        'physical_dims'   => array(
                            'weight_gross' => $itemWeightG,
                            'dx'           => $itemDx,
                            'dy'           => $itemDy,
                            'dz'           => $itemDz,
                        ),
                        'billing_details' => array(
                            'unit_price'          => $unitPriceKopecks,
                            'assessed_unit_price' => $unitPriceKopecks,
                            'nds'                 => -1, // без НДС (УСН)
                        ),
                    );
                }
            }

            if ( empty( $items_list ) ) {
                $order->update_meta_data( 'yd_error', 'Не удалось сопоставить товары заказа с грузоместами для Яндекс Доставки' );
                $order->save();
                return;
            }

            // Сумма товаров (копейки) = как total_assessed_price в pricing-calculator / старый request/create
            $goods_assessed_kop = 0;
            foreach ( $items_list as $it ) {
                if ( isset( $it['billing_details']['unit_price'], $it['count'] ) ) {
                    $goods_assessed_kop += (int) $it['billing_details']['unit_price'] * (int) $it['count'];
                }
            }

            $places_api = array();
            foreach ( $placesPayload['places'] as $pi => $plEntry ) {
                $barcode = ( 1 === $numSeg ) ? 'WC' . $orderIdInt : 'WC' . $orderIdInt . '-' . ( $pi + 1 );
                $places_api[] = array_merge(
                    array( 'barcode' => $barcode ),
                    $plEntry
                );
            }

            $total_weight_g = 0;
            foreach ( $places_api as $plw ) {
                if ( isset( $plw['physical_dims']['weight_gross'] ) ) {
                    $total_weight_g += (int) $plw['physical_dims']['weight_gross'];
                }
            }

            // Определяем адрес отправления
            $pointForParcelName = $order->get_meta( 'yd_reception_point' ) ? $order->get_meta( 'yd_reception_point' ) : $shippingData['object']->get_option( 'reception_point' );
            $sourceAddress = $pointForParcelName ?: get_option( 'woocommerce_store_city', '' );

            // Определяем адрес назначения и тип доставки
            $isSelfPickup = ( strpos( $shippingData['method_id'], 'yd_self' ) !== false );
            $isCod = yd_order_is_pay_on_receipt_for_yd_api( $order, $shippingData['method_id'] );

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

            // Идентификатор заказа (уникальный при повторной отправке)
            $orderIdForApi = ( $shippingData['object']->get_option( 'order_prefix' ) ?
                    $shippingData['object']->get_option( 'order_prefix' ) . '_' : '' )
                             . $order->get_order_number();
            $resendCount = (int) $order->get_meta( 'yd_resend_count' );
            if ( $resendCount > 0 ) {
                $orderIdForApi .= '_r' . $resendCount;
            }

            // Доставка в ЯД — только целые рубли в API (магазин может считать с копейками).
            $deliveryCostKopecks = yd_money_rub_to_api_kopecks( (float) $shippingData['cost'] );
            $delivery_rub_whole  = (int) ( $deliveryCostKopecks / 100 );

            $recipient_names = yd_order_recipient_names_for_yandex( $order );

            $info_comment = sprintf( 'WooCommerce заказ #%s', $order->get_order_number() );
            if ( $isCod ) {
                $order_total_whole_rub = round( (float) $order->get_total() );
                $info_comment          .= sprintf(
                    ' | Наложенный платёж при получении (card_on_receipt). Товары %s ₽ + доставка %s ₽ = всего %s ₽.',
                    wc_format_decimal( $goods_assessed_kop / 100, 0 ),
                    wc_format_decimal( $delivery_rub_whole, 0 ),
                    wc_format_decimal( $order_total_whole_rub, 0 )
                );
            }

            // Собираем тело запроса для Yandex Delivery API
            $request_data = array(
                'info' => array(
                    'operator_request_id' => $orderIdForApi,
                    'comment'             => $info_comment,
                ),
                'source'      => yd_build_source( $sourceAddress, $pointForParcelName ),
                'destination' => yd_build_destination( $isSelfPickup, $destinationAddress, isset( $yd_code ) ? $yd_code : '', $order ),
                'recipient_info' => yd_build_recipient_info_for_create_request( $order, $recipient_names, $customerPhone, $customerEmail ),
                'items'                  => $items_list,
                'places'                 => $places_api,
                'last_mile_policy'       => $isSelfPickup ? 'self_pickup' : 'time_interval',
                'total_assessed_price'   => $goods_assessed_kop,
                'total_weight'           => $total_weight_g,
            );

            // billing_info — обязательное поле API ЯД (структура как в справочнике ЯД)
            if ( $isCod ) {
                $pm_cod = apply_filters( 'yd_billing_payment_method_cod', 'card_on_receipt', $order );
                $request_data['billing_info'] = apply_filters(
                    'yd_billing_info_cod',
                    array(
                        'payment_method'   => $pm_cod,
                        'delivery_cost'    => $deliveryCostKopecks,
                        // Явная сумма товаров (коп.): в ЛК/наложке часто показывается только delivery_cost, если нет aggregate
                        'full_items_price' => $goods_assessed_kop,
                    ),
                    $order,
                    $goods_assessed_kop,
                    $deliveryCostKopecks
                );
            } else {
                $request_data['billing_info'] = array(
                    'payment_method'                       => 'already_paid',
                    'delivery_cost'                        => 0,
                    'variable_delivery_cost_for_recipient' => array(
                        array(
                            'min_cost_of_accepted_items' => 1,
                            'delivery_cost'              => 0,
                        ),
                    ),
                );
            }

            $request_data = yd_request_data_round_money_for_api( $request_data );

            yd_log( 'CREATE REQUEST order #' . $orderId . ' | request_data=' . wp_json_encode( $request_data ), $orderId );

            $answer = $yd_client->create_request( $request_data );

            yd_log( 'CREATE RESPONSE order #' . $orderId . ' | response=' . wp_json_encode( $answer ), $orderId );

            if ( is_wp_error( $answer ) ) {
                $errorMsg = $answer->get_error_message();
                $order->update_meta_data( 'yd_error', $errorMsg );
                $order->save();
                yd_log( 'CREATE ERROR order #' . $orderId . ': ' . $errorMsg, $orderId );
                return;
            }

            $requestId = isset( $answer['request_id'] ) ? $answer['request_id'] : '';

            if ( ! empty( $requestId ) ) {
                $order->update_meta_data( 'yd_tracking_number', $requestId );

                // Этикетку сразу после создания заявки НЕ запрашиваем (409 — ещё не готова).
                // Если label_url пришёл в ответе create_request — сохраняем, иначе
                // админ скачает этикетку вручную кнопкой «Скачать этикетку» в мета-боксе.
                $labelUrl = '';
                if ( isset( $answer['label_url'] ) ) {
                    $labelUrl = $answer['label_url'];
                }

                $info = $yd_client->get_request_info( $requestId );
                if ( ! is_wp_error( $info ) ) {
                    yd_persist_yandex_request_info_extras( $order, $info );
                    if ( isset( $info['state'] ) && is_array( $info['state'] ) ) {
                        $st = $info['state'];
                        $order->update_meta_data( 'yd_last_status', isset( $st['description'] ) ? $st['description'] : ( isset( $st['status'] ) ? (string) $st['status'] : '' ) );
                        $order->update_meta_data( 'yd_last_status_code', isset( $st['status'] ) ? (string) $st['status'] : '' );
                        $sd = yd_format_yandex_event_time( $st );
                        $order->update_meta_data( 'yd_last_status_date', $sd !== '' ? $sd : current_time( 'd.m.Y H:i' ) );
                    }
                    $hist = $yd_client->get_request_history( $requestId );
                    $ev   = yd_yandex_history_events( $hist );
                    if ( ! is_wp_error( $hist ) && ! empty( $ev ) ) {
                        $order->update_meta_data( 'yd_tracking_history', wp_json_encode( $ev ) );
                    }
                }

                $order->update_meta_data( 'yd_link', $labelUrl );
                $order->delete_meta_data( 'yd_error' );
                $order->save();
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

                    // Показываем выбранный ПВЗ из cookie (переживает update_checkout)
                    $pvz_address = isset( $_COOKIE['yd_pvz_address'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) ) : '';
                    $pvz_code    = isset( $_COOKIE['yd_pvz_code'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) : '';
                    if ( $pvz_address && $pvz_code ) {
                        echo '<div class="nd-pvz-selected" style="margin:8px 0 4px 15px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:14px;color:#166534;"><strong>ПВЗ:</strong> ' . esc_html( $pvz_address ) . '</div>';
                    }
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

        $city         = isset( $_POST['city'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['city'] ) ) ) : '';
        $payment_after = isset( $_POST['payment_after'] ) && ( $_POST['payment_after'] === '1' || $_POST['payment_after'] === 1 );

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
            foreach ( yd_all_method_ids() as $mid ) {
                $s = get_option( 'woocommerce_' . $mid . '_settings' );
                if ( is_array( $s ) && ! empty( $s['key'] ) ) {
                    yd_add_reception_points( $s['key'] );
                    break;
                }
            }
        }

        // Ищем в локальной БД СТРОГО по колонке city (там чистое имя города
        // из address.locality, напр. "Псков", "Калининград").
        // По name (полному адресу) искать НЕЛЬЗЯ — "Псков" поймает "Псковская улица" в Москве.
        //
        // Стратегия в 3 шага от строгого к мягкому:
        //   1) city = 'Псков' (без учёта регистра)
        //   2) city LIKE 'Псков%' — на случай "Псков город"
        //   3) city LIKE '%Псков%' — на случай "г. Псков", "г Псков" и т.п.
        // 3-символьный фолбэк удалён — из-за него "Калининград" находил "Калугу".
        $cod_where = $payment_after ? ' AND cash_allowed = 1' : '';

        $cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
        if ( ! in_array( 'cash_allowed', $cols ) ) {
            $cod_where = '';
        }
        $select_cash = in_array( 'cash_allowed', $cols ) ? ', cash_allowed' : ', 1 AS cash_allowed';

        $city_norm       = mb_strtolower( $city );
        $like_exact      = $wpdb->esc_like( $city );
        $like_prefix     = $wpdb->esc_like( $city ) . '%';
        $like_contains   = '%' . $wpdb->esc_like( $city ) . '%';

        // Раньше стояло 500 — в Москве/СПб обрезало список. Поднимаем до 3000 и делаем фильтруемым.
        $limit = (int) apply_filters( 'yd_pvz_query_limit', 3000, $city );
        if ( $limit <= 0 ) { $limit = 3000; }

        // 1) Точное совпадение города (регистронезависимо)
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT code, name, city, lat, lng, schedule{$select_cash}
             FROM `{$table}`
             WHERE LOWER(city) = %s{$cod_where}
             ORDER BY name LIMIT %d",
            $city_norm,
            $limit
        ) );

        // 2) Префиксное совпадение ("Псков…")
        if ( empty( $results ) ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT code, name, city, lat, lng, schedule{$select_cash}
                 FROM `{$table}`
                 WHERE city LIKE %s{$cod_where}
                 ORDER BY name LIMIT %d",
                $like_prefix,
                $limit
            ) );
        }

        // 3) Подстрока ("г. Псков")
        if ( empty( $results ) ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT code, name, city, lat, lng, schedule{$select_cash}
                 FROM `{$table}`
                 WHERE city LIKE %s{$cod_where}
                 ORDER BY name LIMIT %d",
                $like_contains,
                $limit
            ) );
        }

        // Диагностика для админа: сколько всего ПВЗ в БД и сколько в этом городе (без лимита).
        if ( function_exists( 'yd_log' ) ) {
            $total_city = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE LOWER(city) = %s{$cod_where}",
                $city_norm
            ) );
            yd_log( sprintf(
                '[YD PVZ] city="%s" cod=%d returned=%d city_total_in_db=%d (limit=%d)',
                $city,
                $payment_after ? 1 : 0,
                is_array( $results ) ? count( $results ) : 0,
                $total_city,
                $limit
            ) );
        }

        $points = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $points[] = array(
                    'id'           => $row->code,
                    'name'         => $row->name,
                    'address'      => $row->name,
                    'lat'          => (float) $row->lat,
                    'lng'          => (float) $row->lng,
                    'schedule'     => $row->schedule ?: '',
                    'cash_allowed' => (int) $row->cash_allowed,
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
            $yd_ver = get_plugin_data( __FILE__ )['Version'] ?? '2.12.0';
            wp_enqueue_script( 'yd_pvz_widget', plugin_dir_url( __FILE__ ) . 'js/yd-pvz-widget.js', array( 'jquery' ), $yd_ver, true );

            wp_enqueue_script( 'yd_script_handle', plugin_dir_url( __FILE__ ) . 'js/yandex-dostavka.js', array( 'jquery', 'yd_pvz_widget' ), $yd_ver );

            wp_register_style( 'yd_button', plugin_dir_url( __FILE__ ) . 'css/yandex-dostavka.css', array(), $yd_ver );

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
                    $order->update_meta_data( 'yd_address', sanitize_text_field( rawurldecode( wp_unslash( isset( $_COOKIE['yd_pvz_address'] ) ? $_COOKIE['yd_pvz_address'] : '' ) ) ) );
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

            if ( in_array( $shipping_method_name, yd_all_method_ids(), true ) ) {
                if ( isset( $_COOKIE['yd_pvz_code'], $_COOKIE['yd_pvz_address'] ) ) {
                    $order->update_meta_data( 'yd_code', sanitize_text_field( wp_unslash( $_COOKIE['yd_pvz_code'] ) ) );
                    $order->update_meta_data( 'yd_address', sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) ) );
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
        $code         = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $address      = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
        $cash_allowed = isset( $_POST['cash_allowed'] ) && $_POST['cash_allowed'] === '0' ? '0' : '1';
        if ( $code ) {
            setcookie( 'yd_pvz_cash_allowed', $cash_allowed, array(
                'expires'  => 0,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly'  => false,
                'samesite' => 'Lax',
            ) );
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
            // rawurlencode согласуется с encodeURIComponent в JS
            setcookie( 'yd_pvz_address', rawurlencode( $address ), array(
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
        cash_allowed tinyint(1) NOT NULL DEFAULT 1,
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

        // Загружаем ПВЗ из Yandex Delivery API.
        // type=pickup_point → все обычные ПВЗ для самовывоза покупателем (~30k по РФ).
        // НЕ используем available_for_dropoff=true — это подмножество «куда магазин везёт посылки»,
        // в нём отсутствуют целые города (Псков, Калининград и т.п.), хотя Яндекс туда доставляет.
        $response = wp_remote_post(
            'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform/pickup-points/list',
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization'  => 'Bearer ' . $token,
                    'Content-Type'   => 'application/json',
                    'Accept-Language' => 'ru',
                ),
                'body' => wp_json_encode( array( 'type' => 'pickup_point' ) ),
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

        // Проверяем наличие новых колонок (на случай если миграция не прошла)
        $cols_rp = $wpdb->get_col( "DESCRIBE `{$table_name}`", 0 );

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

            // Определяем способы оплаты в ПВЗ из API pickup-points/list.
            // Яндекс может вернуть поле под разными именами и в разном формате:
            //   - payment_methods: ['postpay','card_on_receipt']
            //   - payment_method : 'card_on_receipt'
            //   - allowed_payment_methods: [{type:'postpay'}, {type:'card_on_receipt'}]
            // Нормализуем в простой массив строк.
            $pm_raw = null;
            foreach ( array( 'payment_methods', 'payment_method', 'allowed_payment_methods' ) as $k ) {
                if ( isset( $point[ $k ] ) && $point[ $k ] !== '' ) {
                    $pm_raw = $point[ $k ];
                    break;
                }
            }
            $pm_list = array();
            if ( is_string( $pm_raw ) ) {
                $pm_list[] = $pm_raw;
            } elseif ( is_array( $pm_raw ) ) {
                foreach ( $pm_raw as $pm_item ) {
                    if ( is_string( $pm_item ) ) {
                        $pm_list[] = $pm_item;
                    } elseif ( is_array( $pm_item ) ) {
                        if ( isset( $pm_item['type'] ) ) {
                            $pm_list[] = (string) $pm_item['type'];
                        } elseif ( isset( $pm_item['name'] ) ) {
                            $pm_list[] = (string) $pm_item['name'];
                        }
                    }
                }
            }
            $pm_list = array_values( array_unique( array_filter( array_map( 'strval', $pm_list ) ) ) );

            // cash_allowed = 1, если ПВЗ принимает card_on_receipt (физическая оплата в терминале).
            // Если API вообще не вернул payment_methods — считаем 1 (не скрываем точки),
            // но запасные поля cash/payment_on_delivery уважаем.
            if ( ! empty( $pm_list ) ) {
                $cash_allowed = in_array( 'card_on_receipt', $pm_list, true ) ? 1 : 0;
            } elseif ( isset( $point['cash'] ) ) {
                $cash_allowed = (int) (bool) $point['cash'];
            } elseif ( isset( $point['payment_on_delivery'] ) ) {
                $cash_allowed = (int) (bool) $point['payment_on_delivery'];
            } else {
                $cash_allowed = 1;
            }

            $pm_joined = implode( ',', $pm_list );

            $has_pm_col = in_array( 'payment_methods', $cols_rp, true );
            $row_data   = array(
                'code'         => $point['id'],
                'name'         => $name,
                'city'         => $city,
                'lat'          => $lat,
                'lng'          => $lng,
                'schedule'     => $schedule,
                'cash_allowed' => $cash_allowed,
            );
            $row_fmt = array( '%s', '%s', '%s', '%f', '%f', '%s', '%d' );
            if ( $has_pm_col ) {
                $row_data['payment_methods'] = $pm_joined;
                $row_fmt[]                   = '%s';
            }

            $result = $wpdb->insert( $table_name, $row_data, $row_fmt );
            if ( $result ) {
                $inserted++;
            }
        }

        error_log('[YD] add_reception_points: inserted ' . $inserted . ' of ' . count($points));
        // Метка последнего успешного рефреша — используется для stale-detection в yd_maybe_refresh_reception_points_stale()
        update_option( 'yd_pvz_last_refresh', time(), false );
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
                foreach ( yd_all_method_ids() as $mid ) {
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
            $pvz_address = isset( $_COOKIE['yd_pvz_address'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_COOKIE['yd_pvz_address'] ) ) ) : 'ПВЗ Яндекс Доставки';
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

    /**
     * Письма WooCommerce: трек оператора и ссылка отслеживания Яндекса (после таблицы заказа).
     */
    add_action( 'woocommerce_email_after_order_table', 'yd_email_yandex_tracking_block', 15, 4 );
    function yd_email_yandex_tracking_block( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $ship = bxbGetShippingData( $order );
        if ( empty( $ship['method_id'] ) || strpos( $ship['method_id'], 'yd' ) === false ) {
            return;
        }
        $rid = $order->get_meta( 'yd_tracking_number' );
        if ( $rid === '' ) {
            return;
        }
        $courier = $order->get_meta( 'yd_courier_order_id' );
        $share   = $order->get_meta( 'yd_sharing_url' );
        $pvcode  = $order->get_meta( 'yd_pickup_code' );

        if ( $plain_text ) {
            echo "\n" . "--- Яндекс Доставка ---\n";
            echo 'ID заявки: ' . $rid . "\n";
            if ( $courier ) {
                echo 'Трек оператора: ' . $courier . "\n";
            }
            if ( $share ) {
                echo 'Отследить заказ: ' . $share . "\n";
            }
            if ( $pvcode ) {
                echo 'Код получения в ПВЗ: ' . $pvcode . "\n";
            }
            return;
        }

        echo '<div style="margin:18px 0;padding:14px;border:1px solid #ddd;border-radius:6px;background:#f7f7f7;font-family:sans-serif;font-size:14px;line-height:1.5;">';
        echo '<p style="margin:0 0 8px;font-weight:bold;">Яндекс Доставка</p>';
        echo '<p style="margin:4px 0;"><strong>Номер заявки:</strong> ' . esc_html( $rid ) . '</p>';
        if ( $courier ) {
            echo '<p style="margin:4px 0;"><strong>Трек оператора:</strong> ' . esc_html( $courier ) . '</p>';
        }
        if ( $pvcode ) {
            echo '<p style="margin:4px 0;"><strong>Код в пункте выдачи:</strong> ' . esc_html( $pvcode ) . '</p>';
        }
        if ( $share ) {
            echo '<p style="margin:10px 0 0;"><a href="' . esc_url( $share ) . '" style="display:inline-block;padding:10px 16px;background:#fc3f1e;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">Отследить доставку</a></p>';
        }
        echo '</div>';
    }

    /**
     * ЛК клиента / просмотр заказа: та же ссылка отслеживания Яндекса, что в письме (без отдельной страницы на сайте).
     */
    add_action( 'woocommerce_order_details_after_order_table', 'yd_customer_order_yandex_tracking_block', 15, 1 );
    function yd_customer_order_yandex_tracking_block( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $ship = bxbGetShippingData( $order );
        if ( empty( $ship['method_id'] ) || strpos( $ship['method_id'], 'yd' ) === false ) {
            return;
        }
        $rid = $order->get_meta( 'yd_tracking_number' );
        if ( $rid === '' ) {
            return;
        }
        $courier = $order->get_meta( 'yd_courier_order_id' );
        $share   = $order->get_meta( 'yd_sharing_url' );
        $pvcode  = $order->get_meta( 'yd_pickup_code' );
        echo '<section class="woocommerce-yd-tracking" style="margin:18px 0;padding:14px;border:1px solid #ddd;border-radius:6px;background:#f7f7f7;font-size:14px;line-height:1.5;">';
        echo '<h2 style="margin:0 0 10px;font-size:1.1em;">' . esc_html__( 'Яндекс Доставка', 'yandex-dostavka' ) . '</h2>';
        echo '<p style="margin:4px 0;"><strong>' . esc_html__( 'Номер заявки:', 'yandex-dostavka' ) . '</strong> ' . esc_html( $rid ) . '</p>';
        if ( $courier ) {
            echo '<p style="margin:4px 0;"><strong>' . esc_html__( 'Трек оператора:', 'yandex-dostavka' ) . '</strong> ' . esc_html( $courier ) . '</p>';
        }
        if ( $pvcode ) {
            echo '<p style="margin:4px 0;"><strong>' . esc_html__( 'Код в пункте выдачи:', 'yandex-dostavka' ) . '</strong> ' . esc_html( $pvcode ) . '</p>';
        }
        if ( $share ) {
            echo '<p style="margin:12px 0 0;"><a class="button" href="' . esc_url( $share ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:10px 16px;background:#fc3f1e;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html__( 'Отследить доставку на сайте Яндекса', 'yandex-dostavka' ) . '</a></p>';
        } else {
            echo '<p style="margin:10px 0 0;color:#555;font-size:13px;">' . esc_html__( 'Персональная ссылка на отслеживание появится после обработки заявки Яндексом; обычно также приходит SMS со ссылкой.', 'yandex-dostavka' ) . '</p>';
        }
        echo '</section>';
    }

    // Заголовок формы: «Ваши данные» — ловим и английский оригинал, и русский перевод
    add_filter( 'gettext', function( $translated, $text, $domain ) {
        if ( $domain === 'woocommerce' && ( $text === 'Billing details' || $translated === 'Оплата и доставка' ) ) {
            return 'Ваши данные';
        }
        return $translated;
    }, 10, 3 );
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

// ═══ Колонка «ЯД Доставка» в списке заказов WooCommerce ═══

add_filter( 'manage_edit-shop_order_columns', 'yd_add_order_column', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'yd_add_order_column', 20 ); // HPOS
function yd_add_order_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' ) {
            $new['yd_delivery'] = 'ЯД Доставка';
        }
    }
    return $new;
}

add_action( 'manage_shop_order_posts_custom_column', 'yd_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'yd_render_order_column_hpos', 10, 2 ); // HPOS
function yd_render_order_column( $column, $post_id ) {
    if ( $column !== 'yd_delivery' ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order ) return;
    yd_render_order_column_content( $order );
}
function yd_render_order_column_hpos( $column, $order ) {
    if ( $column !== 'yd_delivery' ) return;
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order ) return;
    yd_render_order_column_content( $order );
}
function yd_render_order_column_content( $order ) {
    $tracking = $order->get_meta( 'yd_tracking_number' );
    $status   = $order->get_meta( 'yd_last_status' );
    $error    = $order->get_meta( 'yd_error' );

    $shippingData = bxbGetShippingData( $order );
    $isYd = isset( $shippingData['method_id'] ) && strpos( $shippingData['method_id'], 'yd' ) !== false;

    if ( ! $isYd ) {
        echo '<span style="color:#ccc;">—</span>';
        return;
    }

    if ( $tracking ) {
        $courier = $order->get_meta( 'yd_courier_order_id' );
        $show    = $courier ? $courier : $tracking;
        $short   = mb_strlen( $show ) > 14 ? mb_substr( $show, 0, 14 ) . '…' : $show;
        $title   = $courier
            ? sprintf( 'Оператор: %s | request_id: %s', $courier, $tracking )
            : $tracking;
        echo '<span title="' . esc_attr( $title ) . '" style="font-size:11px;font-family:monospace;">' . esc_html( $short ) . '</span><br>';
        // Статус
        if ( $status ) {
            echo '<span style="font-size:11px;color:#666;">' . esc_html( $status ) . '</span>';
        } else {
            echo '<span style="font-size:11px;color:#999;">ожидание</span>';
        }
        // Оплата при получении
        if ( yd_order_is_pay_on_receipt_for_yd_api( $order, $shippingData['method_id'] ) ) {
            echo '<br><span style="font-size:10px;color:#b26200;">&#128176; ' . esc_html( strip_tags( wc_price( $order->get_total(), array( 'decimals' => 0 ) ) ) ) . '</span>';
        }
    } elseif ( $error ) {
        echo '<span style="color:#c00;font-size:11px;" title="' . esc_attr( $error ) . '">&#10060; ошибка</span>';
    } else {
        echo '<span style="color:#999;font-size:11px;">не отправлен</span>';
    }
}

// ═══ Массовая синхронизация статусов (кнопка в админке) ═══

add_action( 'admin_notices', 'yd_bulk_sync_admin_notice' );
function yd_bulk_sync_admin_notice() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) return;
    $screen = get_current_screen();
    if ( ! $screen ) return;
    $is_orders = ( $screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders' );
    if ( ! $is_orders ) return;

    // Обработка нажатия
    if ( isset( $_GET['yd_bulk_sync'] ) && wp_verify_nonce( $_GET['_yd_nonce'] ?? '', 'yd_bulk_sync' ) ) {
        yd_sync_order_statuses();
        echo '<div class="notice notice-success"><p>Яндекс Доставка: статусы синхронизированы.</p></div>';
        return;
    }

    $sync_url = wp_nonce_url( add_query_arg( 'yd_bulk_sync', '1' ), 'yd_bulk_sync', '_yd_nonce' );
    echo '<div class="notice notice-info" style="padding:8px 12px;display:flex;align-items:center;gap:12px;">';
    echo '<span>Яндекс Доставка</span>';
    echo '<a href="' . esc_url( $sync_url ) . '" class="button button-small">Синхронизировать статусы</a>';
    echo '</div>';
}