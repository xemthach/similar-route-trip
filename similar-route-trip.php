<?php
/**
 * Plugin Name:       Tuyen Di Pho Bien (Simular Route Trip)
 * Plugin URI:        https://example.com/similar-route-trip
 * Description:       Independent popular taxi route manager, SEO landing content, shortcodes, schema, and Distance Calculator pricing bridge.
 * Version:           0.5.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Flavor Mien Tay
 * Text Domain:       similar-route-trip
 * Domain Path:       /languages
 *
 * @package SimilarRouteTrip
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SRT_VERSION', '0.5.1' );
define( 'SRT_DB_VERSION', '0.5.1' );
define( 'SRT_PLUGIN_FILE', __FILE__ );
define( 'SRT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SRT_TABLE_NAME', 'srt_routes' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SimilarRouteTrip\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = SRT_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, [ SimilarRouteTrip\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ SimilarRouteTrip\Core\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		SimilarRouteTrip\Core\Plugin::instance()->boot();
	}
);
