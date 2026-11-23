<?php
/**
 * Plugin Name: CodexaWP Shipment Tracking Bridge
 * Description: Receives signed events from a sample carrier and displays tracking in WooCommerce.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace CodexaWP\Shipment;

defined( 'ABSPATH' ) || exit;

const META = '_codexawp_shipment';
const STAGES = [ 'dispatched' => 1, 'in_transit' => 2, 'delivered' => 3 ];

add_action( 'before_woocommerce_init', static function (): void {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
} );

function authorize( \WP_REST_Request $request ): bool|\WP_Error {
	$secret = defined( 'CODEXAWP_CARRIER_SECRET' ) ? CODEXAWP_CARRIER_SECRET : '';
	$timestamp = $request->get_header( 'X-Carrier-Timestamp' );
	$signature = $request->get_header( 'X-Carrier-Signature' );
	if (
		! is_string( $secret ) || strlen( $secret ) < 24 ||
		! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ||
		! hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret ), $signature )
	) {
		return new \WP_Error( 'carrier_signature', 'Invalid or expired carrier signature.', [ 'status' => 401 ] );
	}
	return true;
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'codexawp-carrier/v1', '/events', [
		'methods'             => 'POST',
		'permission_callback' => __NAMESPACE__ . '\authorize',
		'callback'            => __NAMESPACE__ . '\receive',
	] );
} );

function receive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	global $wpdb;
	$data = $request->get_json_params();
	$id = $data['orderId'] ?? null;
	$event = $data['eventId'] ?? null;
	$tracking = $data['tracking'] ?? null;
	$stage = $data['stage'] ?? null;
	$sequence = $data['sequence'] ?? null;
	if (
		! is_int( $id ) || $id < 1 || ! is_int( $sequence ) || $sequence < 1 ||
		! is_string( $stage ) || ! isset( STAGES[$stage] ) ||
		! is_string( $event ) || ! preg_match( '/\A[A-Za-z0-9-]{1,64}\z/', $event ) ||
		! is_string( $tracking ) || ! preg_match( '/\A[A-Za-z0-9-]{1,40}\z/', $tracking )
	) {
		return new \WP_Error( 'carrier_payload', 'Invalid carrier event.', [ 'status' => 400 ] );
	}
	$lock = 'codexa.ship.' . md5( $wpdb->prefix . $id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
		return new \WP_Error( 'carrier_busy', 'Please retry the event.', [ 'status' => 503 ] );
	}
	try {
		$order = wc_get_order( $id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'carrier_order', 'Order not found.', [ 'status' => 404 ] );
		}
		$previous = $order->get_meta( META );
		if ( $previous ) {
			if ( $previous['tracking'] !== $tracking ) {
				return new \WP_Error( 'carrier_tracking', 'The demo accepts one tracking number per order.', [ 'status' => 409 ] );
			}
			if ( $previous['event'] === $event || $previous['sequence'] >= $sequence || STAGES[$previous['stage']] > STAGES[$stage] ) {
				return rest_ensure_response( [ 'result' => 'ignored' ] );
			}
		}
		$order->update_meta_data( META, compact( 'event', 'tracking', 'stage', 'sequence' ) );
		$order->save_meta_data();
		return rest_ensure_response( [ 'result' => 'recorded' ] );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function display( \WC_Order $order ): void {
	$shipment = $order->get_meta( META );
	if ( ! $shipment ) {
		return;
	}
	echo '<p class="codexawp-shipment"><strong>Shipment</strong><br>';
	echo esc_html( $shipment['tracking'] . ' · ' . ucfirst( str_replace( '_', ' ', $shipment['stage'] ) ) );
	echo '</p>';
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', __NAMESPACE__ . '\display' );
add_action( 'woocommerce_order_details_after_order_table', __NAMESPACE__ . '\display' );
