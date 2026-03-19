<?php
/**
 * Yandex Delivery API Client
 *
 * Обёртка для Yandex Delivery B2B API.
 *
 * API docs: https://yandex.ru/support/delivery-profile/ru/api/other-day/access
 * Base URL: https://b2b-authproxy.taxi.yandex.net
 * Auth: Authorization: Bearer <OAuth-token>
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yandex_Delivery_API {

    const BASE_URL = 'https://b2b-authproxy.taxi.yandex.net';

    /** @var string OAuth Bearer token */
    private $token;

    /** @var int HTTP timeout in seconds */
    private $timeout = 30;

    /** @var string|null Last error message */
    private $last_error = null;

    /**
     * @param string $token OAuth Bearer token (y0__...)
     */
    public function __construct( $token ) {
        $this->token = $token;
    }

    /**
     * Set timeout for API requests.
     *
     * @param int $seconds
     */
    public function set_timeout( $seconds ) {
        $this->timeout = (int) $seconds;
    }

    /**
     * Get last error message.
     *
     * @return string|null
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Make POST request to Yandex Delivery API.
     *
     * @param string $endpoint API path (e.g. '/api/b2b/platform/pricing-calculator')
     * @param array  $body     Request body (will be JSON-encoded)
     * @return array|WP_Error  Decoded JSON response or WP_Error
     */
    public function post( $endpoint, $body = array() ) {
        $this->last_error = null;

        $response = wp_remote_post(
            self::BASE_URL . $endpoint,
            array(
                'timeout' => $this->timeout,
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $this->token,
                    'Content-Type'    => 'application/json',
                    'Accept-Language' => 'ru',
                ),
                'body' => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            error_log( '[YD API] HTTP error: ' . $this->last_error );
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 ) {
            $msg = isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $status;
            $this->last_error = $msg;
            error_log( '[YD API] Error ' . $status . ': ' . $msg );
            return new WP_Error( 'yd_api_error', $msg, array( 'status' => $status ) );
        }

        return $data;
    }

    // ─── Pricing ─────────────────────────────────────────────

    /**
     * Рассчитать стоимость доставки.
     *
     * @param string $source_address      Адрес отправления
     * @param string $destination_address  Адрес назначения
     * @param string $tariff              'time_interval' | 'self_pickup'
     * @param int    $weight_grams        Вес в граммах
     * @param int    $assessed_price      Оценочная стоимость (руб, копейки)
     * @param array  $dimensions          ['length' => cm, 'width' => cm, 'height' => cm]
     * @return array|WP_Error
     */
    public function calculate_price( $source_address, $destination_address, $tariff, $weight_grams, $assessed_price, $dimensions = array() ) {
        $dx = isset( $dimensions['length'] ) ? (int) $dimensions['length'] : 10;
        $dy = isset( $dimensions['width'] )  ? (int) $dimensions['width']  : 10;
        $dz = isset( $dimensions['height'] ) ? (int) $dimensions['height'] : 10;

        return $this->post( '/api/b2b/platform/pricing-calculator', array(
            'source'               => array( 'address' => $source_address ),
            'destination'          => array( 'address' => $destination_address ),
            'tariff'               => $tariff,
            'total_assessed_price' => (int) $assessed_price,
            'total_weight'         => (int) $weight_grams,
            'places'               => array(
                array(
                    'physical_dims' => array(
                        'dx'          => $dx,
                        'dy'          => $dy,
                        'dz'          => $dz,
                        'weight_gross' => (int) $weight_grams,
                    ),
                ),
            ),
        ) );
    }

    // ─── Pickup Points ───────────────────────────────────────

    /**
     * Получить список точек самопривоза (дропоффов).
     *
     * @param array $filters  Optional filters (available_for_dropoff, geo_id, type, etc.)
     * @return array|WP_Error
     */
    public function get_pickup_points( $filters = array() ) {
        $this->set_timeout( 60 );
        $body = ! empty( $filters ) ? $filters : array( 'available_for_dropoff' => true );
        return $this->post( '/api/b2b/platform/pickup-points/list', $body );
    }

    // ─── Orders ──────────────────────────────────────────────

    /**
     * Создать оффер (предложение доставки).
     *
     * @param array $offer_data
     * @return array|WP_Error
     */
    public function create_offer( $offer_data ) {
        return $this->post( '/api/b2b/platform/offers/create', $offer_data );
    }

    /**
     * Создать заказ на доставку.
     *
     * @param array $request_data
     * @return array|WP_Error
     */
    public function create_request( $request_data ) {
        return $this->post( '/api/b2b/platform/request/create', $request_data );
    }

    /**
     * Подтвердить заказ.
     *
     * @param string $request_id
     * @return array|WP_Error
     */
    public function confirm_request( $request_id ) {
        return $this->post( '/api/b2b/platform/request/confirm', array(
            'request_id' => $request_id,
        ) );
    }

    /**
     * Получить информацию о заказе (включая статус).
     *
     * @param string $request_id
     * @return array|WP_Error
     */
    public function get_request_info( $request_id ) {
        return $this->post( '/api/b2b/platform/request/info', array(
            'request_id' => $request_id,
        ) );
    }

    /**
     * Получить историю статусов заказа.
     *
     * @param string $request_id
     * @return array|WP_Error
     */
    public function get_request_history( $request_id ) {
        return $this->post( '/api/b2b/platform/request/history', array(
            'request_id' => $request_id,
        ) );
    }

    // ─── Labels & Acts ───────────────────────────────────────

    /**
     * Сгенерировать ярлыки для заказов.
     *
     * @param array $request_ids  Массив request_id
     * @return array|WP_Error
     */
    public function generate_labels( $request_ids ) {
        return $this->post( '/api/b2b/platform/request/generate-labels', array(
            'request_ids' => (array) $request_ids,
        ) );
    }

    /**
     * Сгенерировать акт передачи.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public function generate_act( $data ) {
        return $this->post( '/api/b2b/platform/request/generate-act', $data );
    }

    // ─── Delivery Methods ────────────────────────────────────

    /**
     * Получить доступные методы доставки.
     *
     * @param float $lat  Latitude
     * @param float $lon  Longitude
     * @return array|WP_Error
     */
    public function get_delivery_methods( $lat, $lon ) {
        return $this->post( '/b2b/cargo/integration/v1/delivery-methods', array(
            'start_point' => array( $lon, $lat ),
        ) );
    }

    // ─── Token Validation ────────────────────────────────────

    /**
     * Проверить валидность токена.
     *
     * @return bool
     */
    public function validate_token() {
        $result = $this->get_pickup_points( array( 'available_for_dropoff' => true ) );
        return ! is_wp_error( $result ) && isset( $result['points'] );
    }
}
