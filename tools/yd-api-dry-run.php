#!/usr/bin/env php
<?php
/**
 * Локальная проверка без WordPress: валидность JSON тел, ожидаемых Яндекс platform API.
 * Реальный вызов API: задайте переменную среды YD_OAUTH_TOKEN и опционально YD_PLATFORM_STATION_FROM / YD_PLATFORM_STATION_TO.
 *
 *   php tools/yd-api-dry-run.php
 *   set YD_OAUTH_TOKEN=y0_... && php tools/yd-api-dry-run.php
 */

$base = 'https://b2b-authproxy.taxi.yandex.net';

// Значения по умолчанию — из реального create (заказ #97859070266398); переопределение: YD_PLATFORM_STATION_FROM / YD_PLATFORM_STATION_TO.
$default_from = '0199f2678b8f7188a7a5dcc200c91003';
$default_to   = 'f8b25778-efa0-4657-915a-ff1ee7b09d1a';

$pricing = array(
	'source'               => array(
		'platform_station_id' => getenv( 'YD_PLATFORM_STATION_FROM' ) ?: $default_from,
	),
	'destination'          => array(
		'platform_station_id' => getenv( 'YD_PLATFORM_STATION_TO' ) ?: $default_to,
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

$pricing_json = json_encode( $pricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( $pricing_json === false ) {
	fwrite( STDERR, "FAIL: json_encode pricing\n" );
	exit( 1 );
}
echo "OK pricing-calculator body length " . strlen( $pricing_json ) . "\n";

$token = getenv( 'YD_OAUTH_TOKEN' );
if ( ! is_string( $token ) || $token === '' ) {
	echo "Skip HTTP: set YD_OAUTH_TOKEN to call {$base}/api/b2b/platform/pricing-calculator\n";
	exit( 0 );
}

if ( ! function_exists( 'curl_init' ) ) {
	fwrite( STDERR, "FAIL: в PHP не включено расширение curl (extension=curl в php.ini).\n" );
	exit( 1 );
}

$url = $base . '/api/b2b/platform/pricing-calculator';
$ch  = curl_init( $url );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => array(
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
			'Accept: application/json',
			'Accept-Language: ru',
		),
		CURLOPT_POSTFIELDS     => $pricing_json,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => 45,
		CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
	)
);

// CA bundle: иначе на Windows часто cURL #60 «unable to get local issuer certificate».
$ca_bundle = '';
$ca_try    = array_filter(
	array(
		getenv( 'YD_CURL_CAINFO' ) ?: '',
		__DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem',
	)
);
foreach ( $ca_try as $p ) {
	if ( $p !== '' && is_readable( $p ) ) {
		$ca_bundle = $p;
		break;
	}
}
if ( $ca_bundle === '' ) {
	foreach ( array( 'curl.cainfo', 'openssl.cafile' ) as $ini_key ) {
		$p = (string) ini_get( $ini_key );
		if ( $p !== '' && is_readable( $p ) ) {
			$ca_bundle = $p;
			break;
		}
	}
}
if ( $ca_bundle !== '' ) {
	curl_setopt( $ch, CURLOPT_CAINFO, $ca_bundle );
}

if ( getenv( 'YD_CURL_VERBOSE' ) === '1' ) {
	curl_setopt( $ch, CURLOPT_VERBOSE, true );
	$verbose = fopen( 'php://stderr', 'w' );
	curl_setopt( $ch, CURLOPT_STDERR, $verbose );
}

$body = curl_exec( $ch );
$errno = curl_errno( $ch );
$errstr = curl_error( $ch );
$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

if ( $body === false || $errno !== 0 ) {
	fwrite( STDERR, "cURL ошибка #{$errno}: {$errstr}\n" );
	fwrite( STDERR, "URL: {$url}\n" );
	if ( $errno === 60 ) {
		$here = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
		fwrite( STDERR, "SSL: скачайте корневые сертификаты в этот каталог:\n" );
		fwrite( STDERR, "  curl.exe -fsSL -o \"{$here}\" \"https://curl.se/ca/cacert.pem\"\n" );
		fwrite( STDERR, "либо: `$env:YD_CURL_CAINFO='C:\\путь\\к\\cacert.pem'` или curl.cainfo / openssl.cafile в php.ini\n" );
	}
	exit( 1 );
}

echo "HTTP {$status}\n";
echo substr( (string) $body, 0, 2000 ) . ( strlen( (string) $body ) > 2000 ? "…\n" : "\n" );
exit( $status >= 200 && $status < 300 ? 0 : 1 );
