<?php
/**
 * Plugin bootstrap.
 *
 * @package SimilarRouteTrip\Core
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Core;

use SimilarRouteTrip\Admin\AdminMenu;
use SimilarRouteTrip\Database\Installer;
use SimilarRouteTrip\REST\RestController;
use SimilarRouteTrip\Schema\SchemaRegistry;
use SimilarRouteTrip\Queue\QueueRunner;
use SimilarRouteTrip\Shortcodes\ShortcodeController;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private const CRON_HOOK = 'srt_queue_cron';

	private static ?self $instance = null;

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Installer::maybe_upgrade();
		add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] );
		add_action( self::CRON_HOOK, [ self::class, 'run_queue_cron' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'five_minutes', self::CRON_HOOK );
		}
		ShortcodeController::init();
		SchemaRegistry::init();

		add_action( 'rest_api_init', [ RestController::class, 'register' ] );

		if ( is_admin() ) {
			AdminMenu::init();
		}
	}

	private function __clone() {}

	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'similar-route-trip' ),
		];
		return $schedules;
	}

	public static function run_queue_cron(): void {
		if ( ! class_exists( QueueRunner::class ) ) {
			return;
		}
		QueueRunner::run_next_batch( 3 );
	}
}
