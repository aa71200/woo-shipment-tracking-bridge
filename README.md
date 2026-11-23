# Shipment Tracking Bridge for WooCommerce

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-shipment-tracking-bridge/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-shipment-tracking-bridge/actions/workflows/checks.yml)

Carrier updates can arrive twice or out of order. This CodexaWP sample accepts signed events and updates one shipment per order while preserving the latest delivery state. Staff and buyers can see the tracking number in the order details.

## Interface preview

<p align="center">
  <a href="docs/order-shipment.png"><img src="docs/order-shipment.png" width="350" alt="Delivered shipment and tracking number in the WooCommerce order editor"></a>
  <br>
  <em>HPOS order editor · delivered shipment with its tracking number and progress</em>
</p>

## Try it

Set `CODEXAWP_CARRIER_SECRET` in the test site's configuration. Send `POST /wp-json/codexawp-carrier/v1/events` with JSON containing `orderId`, `tracking`, `eventId`, `sequence` and `stage`. The accepted stages are `dispatched`, `in_transit` and `delivered`. The test fixture shows how a carrier signs the timestamp and exact request body and exercises this route without a live carrier.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The fixture makes local REST requests and checks invalid, missing and expired signatures, replay handling, monotonic shipment status and saved WooCommerce order metadata. Rejected signatures leave the order unchanged. The bridge models one shipment per order with a synthetic carrier fixture.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
