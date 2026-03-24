<?php
/**
 * Интеграция с Мой Склад: синхронизация заказов WooCommerce в заказы покупателя.
 *
 * @package YD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YD_MoySklad {

	const API_BASE = 'https://online.moysklad.ru/api/remap/1.2/';
	const OPTION_ENABLED = 'yd_moysklad_enabled';
	const OPTION_LOGIN = 'yd_moysklad_login';
	const OPTION_PASSWORD = 'yd_moysklad_password';
	const OPTION_SEND_ON_STATUS = 'yd_moysklad_send_on_status';
	const OPTION_ORGANIZATION_ID = 'yd_moysklad_organization_id';
	const OPTION_DEFAULT_PRODUCT_ID = 'yd_moysklad_default_product_id';
	const ORDER_META_ID = 'yd_moysklad_id';
	const ORDER_META_ERROR = 'yd_moysklad_error';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 15, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 20, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_meta_box' ), 10, 2 );
	}

	public static function add_order_meta_box( $post_type, $post ) {
		if ( ( $post_type !== 'shop_order' && $post_type !== 'wc-order' ) || get_option( self::OPTION_ENABLED, '0' ) !== '1' ) {
			return;
		}
		add_meta_box(
			'yd_moysklad',
			__( 'Мой Склад', 'yandex-dostavka' ),
			array( __CLASS__, 'render_order_meta_box' ),
			$post_type,
			'side'
		);
	}

	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$ms_id = $order->get_meta( self::ORDER_META_ID );
		$ms_error = $order->get_meta( self::ORDER_META_ERROR );
		if ( $ms_id ) {
			$href = 'https://online.moysklad.ru/app/#customerorder/edit?id=' . $ms_id;
			echo '<p>' . esc_html__( 'Заказ передан в Мой Склад (черновик).', 'yandex-dostavka' ) . '</p>';
			echo '<p><a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . esc_html__( 'Открыть в Мой Склад', 'yandex-dostavka' ) . '</a></p>';
		} elseif ( $ms_error ) {
			echo '<p><strong>' . esc_html__( 'Ошибка синхронизации:', 'yandex-dostavka' ) . '</strong><br>' . esc_html( $ms_error ) . '</p>';
			echo '<p><button type="submit" name="yd_moysklad_sync" class="button">' . esc_html__( 'Отправить в Мой Склад', 'yandex-dostavka' ) . '</button></p>';
		} else {
			echo '<p>' . esc_html__( 'Заказ ещё не отправлен в Мой Склад.', 'yandex-dostavka' ) . '</p>';
			echo '<p><button type="submit" name="yd_moysklad_sync" class="button">' . esc_html__( 'Отправить в Мой Склад', 'yandex-dostavka' ) . '</button></p>';
		}
	}

	public static function save_order_meta_box( $order_id, $order ) {
		if ( ! isset( $_POST['yd_moysklad_sync'] ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$ord = wc_get_order( $order_id );
		if ( ! $ord ) {
			return;
		}
		if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $order_id ) ) {
			$result = self::sync_order( $ord );
			if ( is_wp_error( $result ) ) {
				// Error already saved in order meta
			}
		}
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Мой Склад', 'yandex-dostavka' ),
			__( 'Мой Склад', 'yandex-dostavka' ),
			'manage_woocommerce',
			'yandex-dostavka-moysklad',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		$group = 'yd_moysklad';
		register_setting( $group, self::OPTION_ENABLED, array(
			'type' => 'string',
			'sanitize_callback' => function ( $v ) { return $v ? '1' : '0'; },
		) );
		register_setting( $group, self::OPTION_LOGIN, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $group, self::OPTION_PASSWORD, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $group, self::OPTION_SEND_ON_STATUS, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $group, self::OPTION_ORGANIZATION_ID, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( $group, self::OPTION_DEFAULT_PRODUCT_ID, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return;
		}
		$enabled = get_option( self::OPTION_ENABLED, '0' );
		$login = get_option( self::OPTION_LOGIN, '' );
		$password = get_option( self::OPTION_PASSWORD, '' );
		$send_on = get_option( self::OPTION_SEND_ON_STATUS, 'wc-processing' );
		$org_id = get_option( self::OPTION_ORGANIZATION_ID, '' );
		$default_product = get_option( self::OPTION_DEFAULT_PRODUCT_ID, '' );

		if ( isset( $_POST['yd_moysklad_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yd_moysklad_nonce'] ) ), 'yd_moysklad_save' ) ) {
			update_option( self::OPTION_ENABLED, isset( $_POST[ self::OPTION_ENABLED ] ) ? '1' : '0' );
			update_option( self::OPTION_LOGIN, isset( $_POST[ self::OPTION_LOGIN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_LOGIN ] ) ) : '' );
			if ( isset( $_POST[ self::OPTION_PASSWORD ] ) && $_POST[ self::OPTION_PASSWORD ] !== '' ) {
				update_option( self::OPTION_PASSWORD, sanitize_text_field( wp_unslash( $_POST[ self::OPTION_PASSWORD ] ) ) );
			}
			update_option( self::OPTION_SEND_ON_STATUS, isset( $_POST[ self::OPTION_SEND_ON_STATUS ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_SEND_ON_STATUS ] ) ) : 'wc-processing' );
			update_option( self::OPTION_ORGANIZATION_ID, isset( $_POST[ self::OPTION_ORGANIZATION_ID ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_ORGANIZATION_ID ] ) ) : '' );
			update_option( self::OPTION_DEFAULT_PRODUCT_ID, isset( $_POST[ self::OPTION_DEFAULT_PRODUCT_ID ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPTION_DEFAULT_PRODUCT_ID ] ) ) : '' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Настройки сохранены.', 'yandex-dostavka' ) . '</p></div>';
			$enabled = get_option( self::OPTION_ENABLED, '0' );
			$login = get_option( self::OPTION_LOGIN, '' );
			$password = get_option( self::OPTION_PASSWORD, '' );
			$send_on = get_option( self::OPTION_SEND_ON_STATUS, 'wc-processing' );
			$org_id = get_option( self::OPTION_ORGANIZATION_ID, '' );
			$default_product = get_option( self::OPTION_DEFAULT_PRODUCT_ID, '' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Синхронизация с Мой Склад', 'yandex-dostavka' ); ?></h1>
			<p><?php esc_html_e( 'Заказы WooCommerce будут автоматически передаваться в Мой Склад как заказы покупателя при достижении выбранного статуса.', 'yandex-dostavka' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'yd_moysklad_save', 'yd_moysklad_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Включить синхронизацию', 'yandex-dostavka' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" value="1" <?php checked( $enabled, '1' ); ?> /> <?php esc_html_e( 'Да', 'yandex-dostavka' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="ms_login"><?php esc_html_e( 'Логин (email Мой Склад)', 'yandex-dostavka' ); ?></label></th>
						<td><input type="email" id="ms_login" name="<?php echo esc_attr( self::OPTION_LOGIN ); ?>" value="<?php echo esc_attr( $login ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ms_password"><?php esc_html_e( 'Пароль', 'yandex-dostavka' ); ?></label></th>
						<td><input type="password" id="ms_password" name="<?php echo esc_attr( self::OPTION_PASSWORD ); ?>" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••' : ''; ?>" autocomplete="new-password" /><br><small><?php esc_html_e( 'Оставьте пустым, чтобы не менять сохранённый пароль.', 'yandex-dostavka' ); ?></small></td>
					</tr>
					<tr>
						<th><label for="ms_send_on"><?php esc_html_e( 'Отправлять заказ при статусе', 'yandex-dostavka' ); ?></label></th>
						<td>
							<select id="ms_send_on" name="<?php echo esc_attr( self::OPTION_SEND_ON_STATUS ); ?>">
								<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $send_on, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ms_org"><?php esc_html_e( 'ID организации (UUID)', 'yandex-dostavka' ); ?></label></th>
						<td><input type="text" id="ms_org" name="<?php echo esc_attr( self::OPTION_ORGANIZATION_ID ); ?>" value="<?php echo esc_attr( $org_id ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Необязательно — будет взята первая из списка', 'yandex-dostavka' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ms_default_product"><?php esc_html_e( 'ID товара по умолчанию (UUID)', 'yandex-dostavka' ); ?></label></th>
						<td><input type="text" id="ms_default_product" name="<?php echo esc_attr( self::OPTION_DEFAULT_PRODUCT_ID ); ?>" value="<?php echo esc_attr( $default_product ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Для позиций без совпадения по артикулу (SKU)', 'yandex-dostavka' ); ?>" /></td>
					</tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Сохранить', 'yandex-dostavka' ); ?>" /></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Вызывается при смене статуса заказа.
	 */
	public static function on_order_status_changed( $order_id, $old_status, $new_status ) {
		if ( get_option( self::OPTION_ENABLED, '0' ) !== '1' ) {
			return;
		}
		$send_on = get_option( self::OPTION_SEND_ON_STATUS, 'wc-processing' );
		$send_on_normalized = ( strpos( $send_on, 'wc-' ) === 0 ) ? substr( $send_on, 3 ) : $send_on;
		if ( $new_status !== $send_on && $new_status !== $send_on_normalized ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( self::ORDER_META_ID ) ) {
			return;
		}
		self::sync_order( $order );
	}

	/**
	 * Синхронизирует один заказ в Мой Склад.
	 *
	 * @param WC_Order $order
	 * @return array|WP_Error ответ API или ошибка
	 */
	public static function sync_order( $order ) {
		$login = get_option( self::OPTION_LOGIN, '' );
		$password = get_option( self::OPTION_PASSWORD, '' );
		if ( empty( $login ) || empty( $password ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, __( 'Не заданы логин или пароль Мой Склад.', 'yandex-dostavka' ) );
			$order->save();
			return new WP_Error( 'moysklad_config', __( 'Не заданы логин или пароль Мой Склад.', 'yandex-dostavka' ) );
		}

		$auth = base64_encode( $login . ':' . $password );
		$headers = array(
			'Authorization' => 'Basic ' . $auth,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		$organization_meta = self::get_organization_meta( $headers );
		if ( is_wp_error( $organization_meta ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $organization_meta->get_error_message() );
			$order->save();
			return $organization_meta;
		}

		$agent_meta = self::get_or_create_counterparty( $order, $headers );
		if ( is_wp_error( $agent_meta ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $agent_meta->get_error_message() );
			$order->save();
			return $agent_meta;
		}

		$positions = self::build_positions( $order, $headers );
		if ( is_wp_error( $positions ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $positions->get_error_message() );
			$order->save();
			return $positions;
		}

		$order_number = $order->get_order_number();
		$body = array(
			'name'          => (string) $order_number,
			'applicable'    => false, // Создаём как черновик (не проведён)
			'agent'         => array( 'meta' => $agent_meta ),
			'organization'  => array( 'meta' => $organization_meta ),
			'description'   => sprintf(
				/* translators: 1: order id, 2: order url */
				__( 'WooCommerce #%1$s | %2$s', 'yandex-dostavka' ),
				$order_number,
				$order->get_edit_order_url()
			),
			'positions'      => $positions,
		);

		$response = wp_remote_post(
			self::API_BASE . 'entity/customerorder',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->update_meta_data( self::ORDER_META_ERROR, $response->get_error_message() );
			$order->save();
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_json = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_json, true );

		if ( $code >= 400 ) {
			$msg = isset( $data['errors'][0]['error'] ) ? $data['errors'][0]['error'] : $body_json;
			$order->update_meta_data( self::ORDER_META_ERROR, $msg );
			$order->delete_meta_data( self::ORDER_META_ID );
			$order->save();
			return new WP_Error( 'moysklad_api', $msg );
		}

		$id = isset( $data['id'] ) ? $data['id'] : null;
		if ( $id ) {
			$order->update_meta_data( self::ORDER_META_ID, $id );
			$order->delete_meta_data( self::ORDER_META_ERROR );
		}
		$order->save();
		return $data;
	}

	private static function request( $method, $path, $headers, $body = null ) {
		$url = self::API_BASE . ltrim( $path, '/' );
		$args = array( 'timeout' => 25, 'headers' => $headers );
		if ( $body !== null ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}
		if ( $method === 'GET' ) {
			return wp_remote_get( $url, $args );
		}
		return wp_remote_post( $url, $args );
	}

	private static function get_organization_meta( $headers ) {
		$org_id = get_option( self::OPTION_ORGANIZATION_ID, '' );
		if ( $org_id !== '' ) {
			return array(
				'href'       => self::API_BASE . 'entity/organization/' . $org_id,
				'type'       => 'organization',
				'mediaType'  => 'application/json',
			);
		}
		$response = wp_remote_get( self::API_BASE . 'entity/organization?limit=1', array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$rows = isset( $data['rows'] ) ? $data['rows'] : array();
		if ( empty( $rows[0]['meta'] ) ) {
			return new WP_Error( 'moysklad_org', __( 'В Мой Склад не найдена ни одна организация.', 'yandex-dostavka' ) );
		}
		return $rows[0]['meta'];
	}

	private static function get_or_create_counterparty( $order, $headers ) {
		$email = $order->get_billing_email();
		if ( $email ) {
			$url = self::API_BASE . 'entity/counterparty?filter=email=' . rawurlencode( $email ) . '&limit=1';
			$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $data['rows'][0]['meta'] ) ) {
					return $data['rows'][0]['meta'];
				}
			}
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( $name === '' ) {
			$name = $order->get_billing_company() ?: __( 'Покупатель', 'yandex-dostavka' );
		}
		$create = array(
			'name'  => $name,
			'email' => $order->get_billing_email() ?: '',
			'phone' => $order->get_billing_phone() ?: '',
		);
		$response = wp_remote_post(
			self::API_BASE . 'entity/counterparty',
			array( 'timeout' => 20, 'headers' => $headers, 'body' => wp_json_encode( $create ) )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || empty( $data['meta'] ) ) {
			return new WP_Error( 'moysklad_counterparty', isset( $data['errors'][0]['error'] ) ? $data['errors'][0]['error'] : __( 'Не удалось создать контрагента.', 'yandex-dostavka' ) );
		}
		return $data['meta'];
	}

	/**
	 * Цена в копейках для Мой Склад (1 руб = 100).
	 */
	private static function price_to_cents( $price ) {
		return (int) round( (float) $price * 100 );
	}

	private static function build_positions( $order, $headers ) {
		$positions = array();
		$default_product_id = get_option( self::OPTION_DEFAULT_PRODUCT_ID, '' );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			$product = $item->get_product();
			$quantity = (int) $item->get_quantity();
			if ( $quantity < 1 ) {
				continue;
			}
			$price = self::price_to_cents( (float) $item->get_subtotal() / $quantity + (float) $item->get_subtotal_tax() / $quantity );

			$meta = null;
			if ( $product ) {
				$sku = $product->get_sku();
				if ( $sku !== '' ) {
					$meta = self::find_product_meta_by_sku( $sku, $headers );
				}
			}
			if ( ! $meta && $default_product_id !== '' ) {
				$meta = array(
					'href'      => self::API_BASE . 'entity/product/' . $default_product_id,
					'type'      => 'product',
					'mediaType' => 'application/json',
				);
			}
			if ( ! $meta ) {
				continue;
			}
			$positions[] = array(
				'quantity'   => $quantity,
				'price'      => $price,
				'assortment' => array( 'meta' => $meta ),
			);
		}

		if ( empty( $positions ) ) {
			return new WP_Error( 'moysklad_positions', __( 'Не удалось сопоставить позиции заказа с товарами в Мой Склад.', 'yandex-dostavka' ) );
		}

		return $positions;
	}

	private static function find_product_meta_by_sku( $sku, $headers ) {
		$url = self::API_BASE . 'entity/product?filter=article=' . rawurlencode( $sku ) . '&limit=1';
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['rows'][0]['meta'] ) ) {
			return $data['rows'][0]['meta'];
		}
		return null;
	}
}
