<?php
/**
 * @wordpress-plugin
 * Plugin Name:       North Mock
 * Plugin URI:        https://northcommerce.com/
 * Description:       Generate realistic North Commerce test products with real variants and locally saved images, sourced from public store catalogs.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Requires Plugins:  north-commerce
 * Author:            North Commerce
 * Author URI:        https://northcommerce.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       north-commerce-mock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NC_MOCK_VERSION', '1.0.0' );
define( 'NC_MOCK_FILE', __FILE__ );
define( 'NC_MOCK_DIR', __DIR__ );
define( 'NC_MOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'NC_MOCK_SLUG', 'north-mock' );
define( 'NC_MOCK_MIN_CORE', '1.0.0' );
define( 'NC_MOCK_TARGET_COUNT', 150 );
define( 'NC_MOCK_SLUG_PREFIX', 'ncm-' );

spl_autoload_register( static function ( $class ) {
	$prefix = 'NorthCommerceMock\\';
	if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
		return;
	}

	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = NC_MOCK_DIR . '/includes/' . $relative . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, static function () {
	NorthCommerceMock\ImageStore::ensure_directory();
} );

/**
 * Core is loaded after this file (WP sorts `north-commerce-mock` before
 * `north-commerce`). Boot from core's own signal, not at include time.
 */
add_action( 'north-commerce/dependencies/loaded', 'nc_mock_boot' );

function nc_mock_boot() {
	if ( ! defined( 'NORTH_COMMERCE_VERSION' ) ) {
		return;
	}

	if ( version_compare( NORTH_COMMERCE_VERSION, NC_MOCK_MIN_CORE, '<' ) ) {
		return;
	}

	NorthCommerceMock\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'nc_mock_notice_if_not_booted', 20 );

function nc_mock_notice_if_not_booted() {
	if ( NorthCommerceMock\Plugin::booted() ) {
		return;
	}

	add_action( 'admin_notices', static function () {
		$have = defined( 'NORTH_COMMERCE_VERSION' )
			? (string) NORTH_COMMERCE_VERSION
			: __( 'not active', 'north-commerce-mock' );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: required core version, 2: installed core version */
				esc_html__( 'North Mock is inactive: it requires North Commerce %1$s or newer (you have %2$s).', 'north-commerce-mock' ),
				esc_html( NC_MOCK_MIN_CORE ),
				esc_html( $have )
			)
		);
	} );
}
