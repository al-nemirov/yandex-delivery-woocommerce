<?php
/**
 * Админка: проверка ответов API Яндекс Доставки без оформления заказов WooCommerce.
 *
 * Безопасные методы: pricing-calculator, pickup-points/list, GET info/history.
 * request/create — только с явным подтверждением (создаёт реальную заявку в ЯД).
 *
 * @package Yandex_Dostavka
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Найти OAuth-токен из первого включённого метода доставки с id, содержащим «yd».
 *
 * @return string
 */
function yd_api_console_find_shipping_token() {
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return '';
	}

	$try_methods = function ( $methods ) {
		foreach ( $methods as $method ) {
			if ( strpos( $method->id, 'yd' ) === false ) {
				continue;
			}
			$key = $method->get_option( 'key' );
			if ( is_string( $key ) && $key !== '' ) {
				return $key;
			}
		}
		return '';
	};

	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		$zone = WC_Shipping_Zones::get_zone( $zone_data['id'] );
		$t    = $try_methods( $zone->get_shipping_methods( true ) );
		if ( $t !== '' ) {
			return $t;
		}
	}

	$zone0 = new WC_Shipping_Zone( 0 );
	$t     = $try_methods( $zone0->get_shipping_methods( true ) );
	return is_string( $t ) ? $t : '';
}

/**
 * Выполнить HTTP POST к API (сырой ответ для консоли).
 *
 * @param string     $token
 * @param string     $endpoint С путём /api/b2b/platform/...
 * @param array      $body
 * @param int        $timeout
 * @return array{status:int,raw:string,json:mixed}
 */
function yd_api_console_http_post( $token, $endpoint, array $body, $timeout = 45 ) {
	$url = Yandex_Delivery_API::BASE_URL . $endpoint;

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => $timeout,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'Accept-Language' => 'ru',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status' => 0,
			'raw'    => $response->get_error_message(),
			'json'   => null,
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = wp_remote_retrieve_body( $response );
	$json   = json_decode( $raw, true );

	return array(
		'status' => $status,
		'raw'    => $raw,
		'json'   => $json,
	);
}

/**
 * GET к API.
 *
 * @param string     $token
 * @param string     $endpoint
 * @param array      $query
 * @param int        $timeout
 * @return array{status:int,raw:string,json:mixed}
 */
function yd_api_console_http_get( $token, $endpoint, array $query, $timeout = 45 ) {
	$url = Yandex_Delivery_API::BASE_URL . $endpoint;
	if ( ! empty( $query ) ) {
		$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => $timeout,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'Accept'          => 'application/json',
				'Accept-Language' => 'ru',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status' => 0,
			'raw'    => $response->get_error_message(),
			'json'   => null,
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = wp_remote_retrieve_body( $response );
	$json   = json_decode( $raw, true );

	return array(
		'status' => $status,
		'raw'    => $raw,
		'json'   => $json,
	);
}

/**
 * Шаблон JSON для pricing-calculator (ID из успешного create заказа #97859070266398 — при необходимости замените).
 *
 * @return string
 */
function yd_api_console_default_pricing_json() {
	$sample = array(
		'source'               => array(
			'platform_station_id' => '0199f2678b8f7188a7a5dcc200c91003',
		),
		'destination'          => array(
			'platform_station_id' => 'f8b25778-efa0-4657-915a-ff1ee7b09d1a',
		),
		'tariff'               => 'self_pickup',
		'total_assessed_price' => 53600,
		'total_weight'         => 366,
		'places'               => array(
			array(
				'physical_dims' => array(
					'dx'           => 21,
					'dy'           => 14,
					'dz'           => 3,
					'weight_gross' => 366,
				),
			),
		),
	);

	return wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Шаблон тела request/create — только для ручной правки; отправка создаёт заявку в ЯД.
 *
 * @return string
 */
function yd_api_console_danger_create_template_json() {
	$sample = array(
		'info'            => array(
			'operator_request_id' => 'wp_console_test_' . gmdate( 'YmdHis' ),
			'comment'             => 'Тест из консоли WooCommerce (создаёт реальную заявку)',
		),
		'source'          => array(
			'platform_station' => array(
				'platform_id' => '0199f2678b8f7188a7a5dcc200c91003',
			),
		),
		'destination'     => array(
			'type'              => 'platform_station',
			'platform_station' => array(
				'platform_id' => 'f8b25778-efa0-4657-915a-ff1ee7b09d1a',
			),
		),
		'recipient_info'  => array(
			'first_name' => 'Тест',
			'last_name'  => 'Консоль',
			'phone'      => '+79000000000',
			'email'      => 'test@example.com',
		),
		'items'           => array(
			array(
				'article'         => 'TEST-SKU-1',
				'name'            => 'Тестовый товар',
				'price'           => 100,
				'count'           => 1,
				'weight'          => 0,
				'place_barcode'   => 'WC_CONSOLE_1',
				'billing_details' => array(
					'unit_price'          => 10000,
					'assessed_unit_price' => 10000,
					'nds'                 => -1,
				),
			),
		),
		'places'          => array(
			array(
				'barcode'       => 'WC_CONSOLE_1',
				'physical_dims' => array(
					'weight_gross' => 500,
					'dx'           => 21,
					'dy'           => 14,
					'dz'           => 5,
				),
			),
		),
		'last_mile_policy' => 'self_pickup',
		'total_assessed_price' => 10000,
		'total_weight'         => 500,
		'billing_info'     => array(
			'payment_method'   => 'card_on_receipt',
			'delivery_cost'    => 20000,
			'full_items_price' => 10000,
		),
	);

	return wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

add_action( 'admin_menu', 'yd_api_console_register_menu', 100 );
function yd_api_console_register_menu() {
	add_submenu_page(
		'woocommerce',
		'ЯД — Проверка API',
		'ЯД — Проверка API',
		'manage_woocommerce',
		'yandex-dostavka-api-console',
		'yd_api_console_render_page'
	);
}

add_action( 'admin_post_yd_api_console_run', 'yd_api_console_handle_run' );
function yd_api_console_handle_run() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'yandex-dostavka' ) );
	}
	check_admin_referer( 'yd_api_console_run' );

	$redirect = admin_url( 'admin.php?page=yandex-dostavka-api-console' );

	$token_in = isset( $_POST['yd_ac_token'] ) ? sanitize_text_field( wp_unslash( $_POST['yd_ac_token'] ) ) : '';
	$token    = $token_in !== '' ? $token_in : yd_api_console_find_shipping_token();

	$op = isset( $_POST['yd_ac_op'] ) ? sanitize_key( wp_unslash( $_POST['yd_ac_op'] ) ) : 'pricing';

	$out = array(
		'op'       => $op,
		'status'   => 0,
		'preview'  => '',
		'pretty'   => '',
		'error'    => '',
		'endpoint' => '',
	);

	if ( $token === '' ) {
		$out['error'] = 'Укажите OAuth-токен или сохраните ключ в методе Яндекс Доставки (настройки доставки).';
		set_transient( 'yd_api_console_last_' . get_current_user_id(), $out, 300 );
		wp_safe_redirect( $redirect );
		exit;
	}

	switch ( $op ) {
		case 'pricing':
			$out['endpoint'] = '/api/b2b/platform/pricing-calculator';
			$raw_body        = isset( $_POST['yd_ac_body'] ) ? wp_unslash( $_POST['yd_ac_body'] ) : '';
			$body            = json_decode( $raw_body, true );
			if ( ! is_array( $body ) ) {
				$out['error'] = 'Некорректный JSON тела: ' . json_last_error_msg();
				break;
			}
			$out['preview'] = mb_substr( wp_json_encode( $body, JSON_UNESCAPED_UNICODE ), 0, 4000 );
			$r              = yd_api_console_http_post( $token, $out['endpoint'], $body );
			$out['status']  = $r['status'];
			$out['pretty']  = is_array( $r['json'] )
				? wp_json_encode( $r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $r['raw'];
			break;

		case 'pickup_list':
			$out['endpoint'] = '/api/b2b/platform/pickup-points/list';
			$body            = array( 'available_for_dropoff' => true );
			$r               = yd_api_console_http_post( $token, $out['endpoint'], $body );
			$out['status']   = $r['status'];
			$out['pretty']   = is_array( $r['json'] )
				? wp_json_encode( $r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $r['raw'];
			break;

		case 'request_info':
			$rid = isset( $_POST['yd_ac_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['yd_ac_request_id'] ) ) : '';
			if ( $rid === '' ) {
				$out['error'] = 'Укажите request_id.';
				break;
			}
			$out['endpoint'] = '/api/b2b/platform/request/info';
			$r               = yd_api_console_http_get( $token, $out['endpoint'], array( 'request_id' => $rid ) );
			$out['status']   = $r['status'];
			$out['pretty']   = is_array( $r['json'] )
				? wp_json_encode( $r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $r['raw'];
			break;

		case 'request_history':
			$rid = isset( $_POST['yd_ac_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['yd_ac_request_id'] ) ) : '';
			if ( $rid === '' ) {
				$out['error'] = 'Укажите request_id.';
				break;
			}
			$out['endpoint'] = '/api/b2b/platform/request/history';
			$r               = yd_api_console_http_get( $token, $out['endpoint'], array( 'request_id' => $rid ) );
			$out['status']   = $r['status'];
			$out['pretty']   = is_array( $r['json'] )
				? wp_json_encode( $r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $r['raw'];
			break;

		case 'create_request':
			$confirm = isset( $_POST['yd_ac_confirm_create'] ) && '1' === wp_unslash( $_POST['yd_ac_confirm_create'] );
			$dual    = isset( $_POST['yd_ac_confirm_create2'] ) && 'yes' === wp_unslash( $_POST['yd_ac_confirm_create2'] );
			if ( ! $confirm || ! $dual ) {
				$out['error'] = 'Создание заявки отменено: отметьте оба подтверждения.';
				break;
			}
			$out['endpoint'] = '/api/b2b/platform/request/create';
			$raw_body        = isset( $_POST['yd_ac_body_create'] ) ? wp_unslash( $_POST['yd_ac_body_create'] ) : '';
			$body            = json_decode( $raw_body, true );
			if ( ! is_array( $body ) ) {
				$out['error'] = 'Некорректный JSON: ' . json_last_error_msg();
				break;
			}
			$r             = yd_api_console_http_post( $token, $out['endpoint'], $body, 60 );
			$out['status'] = $r['status'];
			$out['pretty'] = is_array( $r['json'] )
				? wp_json_encode( $r['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $r['raw'];
			break;

		default:
			$out['error'] = 'Неизвестная операция.';
	}

	set_transient( 'yd_api_console_last_' . get_current_user_id(), $out, 300 );
	wp_safe_redirect( $redirect );
	exit;
}

function yd_api_console_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'yandex-dostavka' ) );
	}

	$last   = get_transient( 'yd_api_console_last_' . get_current_user_id() );
	$token0 = yd_api_console_find_shipping_token();
	$mask   = static function ( $t ) {
		if ( $t === '' || strlen( $t ) < 12 ) {
			return '(не найден)';
		}
		return esc_html( substr( $t, 0, 6 ) . '…' . substr( $t, -4 ) );
	};

	delete_transient( 'yd_api_console_last_' . get_current_user_id() );

	$default_pricing = yd_api_console_default_pricing_json();
	$default_create  = yd_api_console_danger_create_template_json();

	?>
	<div class="wrap">
		<h1>Проверка API Яндекс Доставки</h1>
		<p class="description">
			Без реальной отправки посылки можно вызывать <strong>pricing-calculator</strong>, <strong>pickup-points/list</strong> и читать <strong>request/info</strong> / <strong>request/history</strong> по ID.
			<code>request/create</code> внизу — только если нужна настоящая заявка в кабинете ЯД.
		</p>
		<p><strong>Токен из настроек доставки:</strong> <?php echo $mask( $token0 ); ?></p>

		<?php if ( is_array( $last ) && ( $last['pretty'] !== '' || $last['error'] !== '' || $last['status'] ) ) : ?>
			<div class="notice <?php echo $last['error'] ? 'notice-error' : ( $last['status'] >= 200 && $last['status'] < 300 ? 'notice-success' : 'notice-warning' ); ?>" style="padding:12px;">
				<p><strong>Последний ответ</strong>
					<?php if ( ! empty( $last['op'] ) ) : ?>
						— операция <code><?php echo esc_html( $last['op'] ); ?></code>
					<?php endif; ?>
					<?php if ( ! empty( $last['endpoint'] ) ) : ?>
						<code><?php echo esc_html( $last['endpoint'] ); ?></code>
					<?php endif; ?>
					— HTTP <strong><?php echo (int) $last['status']; ?></strong>
				</p>
				<?php if ( ! empty( $last['error'] ) ) : ?>
					<p><?php echo esc_html( $last['error'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $last['pretty'] ) ) : ?>
					<pre style="max-height:420px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;border-radius:4px;font-size:12px;"><?php echo esc_html( $last['pretty'] ); ?></pre>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<hr />

		<h2>1. Расчёт стоимости (pricing-calculator)</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:920px;">
			<?php wp_nonce_field( 'yd_api_console_run' ); ?>
			<input type="hidden" name="action" value="yd_api_console_run" />
			<input type="hidden" name="yd_ac_op" value="pricing" />
			<p>
				<label><strong>OAuth-токен</strong> (необязательно, если задан в методе доставки)</label><br />
				<input type="password" name="yd_ac_token" class="large-text" autocomplete="off" placeholder="y0_..." />
			</p>
			<p>
				<label><strong>Тело JSON</strong></label><br />
				<textarea name="yd_ac_body" rows="16" class="large-text code" style="font-family:monospace;"><?php echo esc_textarea( $default_pricing ); ?></textarea>
			</p>
			<?php submit_button( 'Отправить pricing-calculator', 'primary', 'submit', false ); ?>
		</form>

		<hr />

		<h2>2. Проверка токена (pickup-points/list)</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'yd_api_console_run' ); ?>
			<input type="hidden" name="action" value="yd_api_console_run" />
			<input type="hidden" name="yd_ac_op" value="pickup_list" />
			<input type="password" name="yd_ac_token" class="large-text" autocomplete="off" placeholder="Оставьте пустым — возьмём из метода доставки" />
			<?php submit_button( 'Запросить точки (available_for_dropoff)', 'secondary', 'submit', false ); ?>
		</form>

		<hr />

		<h2>3. Инфо и история по request_id</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;">
			<?php wp_nonce_field( 'yd_api_console_run' ); ?>
			<input type="hidden" name="action" value="yd_api_console_run" />
			<p>
				<label><strong>request_id</strong></label><br />
				<input type="text" name="yd_ac_request_id" class="large-text" placeholder="напр. a89b224d3d4e42c18e4814ac76512226-udp" />
			</p>
			<input type="password" name="yd_ac_token" class="large-text" autocomplete="off" placeholder="Токен (опционально)" />
			<p>
				<button class="button button-secondary" name="yd_ac_op" value="request_info">GET request/info</button>
				<button class="button button-secondary" name="yd_ac_op" value="request_history">GET request/history</button>
			</p>
		</form>

		<hr />

		<h2 style="color:#b32d2e;">4. Опасная зона: request/create</h2>
		<p><strong>Создаёт реальную заявку</strong> в Яндекс Доставке. Используйте тестовые ИД точек и данные.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:920px;">
			<?php wp_nonce_field( 'yd_api_console_run' ); ?>
			<input type="hidden" name="action" value="yd_api_console_run" />
			<input type="hidden" name="yd_ac_op" value="create_request" />
			<p>
				<label><input type="checkbox" name="yd_ac_confirm_create" value="1" /> Я понимаю, что будет создана реальная заявка в ЯД</label><br />
				<label><input type="checkbox" name="yd_ac_confirm_create2" value="yes" /> Подтверждаю ещё раз</label>
			</p>
			<p>
				<label>Токен</label><br />
				<input type="password" name="yd_ac_token" class="large-text" autocomplete="off" />
			</p>
			<label>JSON тела create</label>
			<textarea name="yd_ac_body_create" rows="22" class="large-text code" style="font-family:monospace;"><?php echo esc_textarea( $default_create ); ?></textarea>
			<?php submit_button( 'Создать заявку (request/create)', 'delete', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
