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

        $url = self::BASE_URL . $endpoint;

        if ( function_exists( 'yd_log' ) ) {
            yd_log( 'API POST ' . $endpoint . ' | body=' . wp_json_encode( $body ) );
        }

        $response = wp_remote_post(
            $url,
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
        $raw_body = wp_remote_retrieve_body( $response );
        $data   = json_decode( $raw_body, true );

        if ( function_exists( 'yd_log' ) ) {
            yd_log( 'API RESPONSE ' . $endpoint . ' | status=' . $status . ' | body=' . mb_substr( $raw_body, 0, 2000 ) );
        }

        // P2 Fix: принимаем 200, 201, 204 как успешные ответы
        if ( $status < 200 || $status >= 300 ) {
            $msg = isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $status;
            $this->last_error = $msg;
            error_log( '[YD API] Error ' . $status . ': ' . $msg );
            return new WP_Error( 'yd_api_error', $msg, array( 'status' => $status ) );
        }

        // 204 No Content — возвращаем пустой массив
        if ( $status === 204 || $data === null ) {
            return array();
        }

        return $data;
    }

    // ─── Pricing ─────────────────────────────────────────────

    /**
     * Рассчитать стоимость доставки.
     *
     * @param string $source_address          Адрес отправления
     * @param string $destination_address     Адрес назначения
     * @param string $tariff                  'time_interval' | 'self_pickup'
     * @param int    $weight_grams            Вес в граммах
     * @param int    $assessed_price          Оценочная стоимость (копейки)
     * @param array  $dimensions              ['length' => cm, 'width' => cm, 'height' => cm]
     * @param string $source_station_id       platform_station_id склада (из yd_reception_points)
     * @param string $destination_station_id  platform_station_id ПВЗ (из yd_pvz_code cookie)
     * @param array|null $places_custom       Если задан непустой массив — тело pricing-calculator получает эти places
     *                                        (несколько грузомест), total_weight = сумма весов мест; $dimensions не используются.
     * @return array|WP_Error
     */
    public function calculate_price( $source_address, $destination_address, $tariff, $weight_grams, $assessed_price, $dimensions = array(), $source_station_id = '', $destination_station_id = '', $places_custom = null ) {
        $dx = isset( $dimensions['length'] ) ? (int) $dimensions['length'] : 10;
        $dy = isset( $dimensions['width'] )  ? (int) $dimensions['width']  : 10;
        $dz = isset( $dimensions['height'] ) ? (int) $dimensions['height'] : 10;

        // Source: используем platform_station_id если есть (как при создании заказа)
        $source = array();
        if ( ! empty( $source_station_id ) ) {
            $source['platform_station_id'] = $source_station_id;
        } else {
            $source['address'] = $source_address;
        }

        // Destination: для ПВЗ — platform_station_id, для курьера — address
        $destination = array();
        if ( ! empty( $destination_station_id ) ) {
            $destination['platform_station_id'] = $destination_station_id;
        } else {
            $destination['address'] = $destination_address;
        }

        if ( is_array( $places_custom ) && ! empty( $places_custom ) ) {
            $places_body = $places_custom;
        } else {
            $places_body = array(
                array(
                    'physical_dims' => array(
                        'dx'           => $dx,
                        'dy'           => $dy,
                        'dz'           => $dz,
                        'weight_gross' => (int) $weight_grams,
                    ),
                ),
            );
        }

        $body = array(
            'source'               => $source,
            'destination'          => $destination,
            'tariff'               => $tariff,
            'total_assessed_price' => (int) $assessed_price,
            'total_weight'         => (int) $weight_grams,
            'places'               => $places_body,
        );

        // Log request/response only when WP_DEBUG is on; mask addresses to protect PII in prod
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $logBody = $body;
            if ( isset( $logBody['source']['address'] ) ) {
                $logBody['source']['address'] = '***';
            }
            if ( isset( $logBody['destination']['address'] ) ) {
                $logBody['destination']['address'] = '***';
            }
            error_log( '[YD API] pricing-calculator REQUEST: ' . wp_json_encode( $logBody ) );
        }

        $result = $this->post( '/api/b2b/platform/pricing-calculator', $body );

        if ( ! is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[YD API] pricing-calculator RESPONSE: ' . wp_json_encode( $result ) );
        }

        return $result;
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
    /**
     * @deprecated Uses /b2b/cargo/integration/v1/ (logistics API), not /b2b/platform/ (delivery API).
     *             Verify endpoint compatibility before use. May require different contract.
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
