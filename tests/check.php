<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || ! getenv( 'CODEXAWP_TEST_WP_LOAD' ) ) {
	throw new RuntimeException( 'Set CODEXAWP_TEST_WP_LOAD to a disposable WordPress installation.' );
}
define( 'CODEXAWP_CARRIER_SECRET', 'fixture-key-for-signed-events-only' );
require getenv( 'CODEXAWP_TEST_WP_LOAD' );
if ( 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'This fixture requires local WordPress with WooCommerce active.' );
}

function check( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

$order = wc_create_order();
$order->save();
$request = static function ( array $event, bool $signed = true ) use ( $order ): \WP_REST_Response {
	$data = $event + [ 'orderId' => $order->get_id(), 'tracking' => 'DEMO-331' ];
	$body = wp_json_encode( $data );
	$timestamp = (string) time();
	$request = new WP_REST_Request( 'POST', '/codexawp-carrier/v1/events' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'X-Carrier-Timestamp', $timestamp );
	$request->set_header( 'X-Carrier-Signature', $signed ? hash_hmac( 'sha256', $timestamp . '.' . $body, CODEXAWP_CARRIER_SECRET ) : 'invalid' );
	$request->set_body( $body );
	return rest_do_request( $request );
};
$initial = [ 'eventId' => 'E1', 'stage' => 'in_transit', 'sequence' => 1 ];
check( 401 === $request( $initial, false )->get_status(), 'Invalid signature returns 401' );
check( 'recorded' === $request( $initial )->get_data()['result'], 'Signed event accepted' );
check( 'ignored' === $request( $initial )->get_data()['result'], 'Replay ignored' );
check( 'recorded' === $request( [ 'eventId' => 'E3', 'stage' => 'delivered', 'sequence' => 3 ] )->get_data()['result'], 'Delivery recorded' );
check( 'ignored' === $request( [ 'eventId' => 'E2', 'stage' => 'in_transit', 'sequence' => 2 ] )->get_data()['result'], 'Older event cannot regress delivery' );
check( 'delivered' === wc_get_order( $order->get_id() )->get_meta( '_codexawp_shipment' )['stage'], 'WooCommerce order retains delivery state' );
echo 'Fixture order: ' . $order->get_id() . PHP_EOL;
