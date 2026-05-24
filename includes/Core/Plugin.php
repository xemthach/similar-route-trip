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
use SimilarRouteTrip\Queue\QueueWorkerConfig;
use SimilarRouteTrip\Queue\QueueRunner;
use SimilarRouteTrip\Queue\Worker;
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
		self::ensure_cron_schedule();
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
		$schedules['one_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every 1 minute', 'similar-route-trip' ),
		];
		$schedules['five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'similar-route-trip' ),
		];
		$schedules['fifteen_minutes'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'similar-route-trip' ),
		];
		return $schedules;
	}

	public static function run_queue_cron(): void {
		$config = QueueWorkerConfig::get();
		if ( ! empty( $config['paused'] ) ) {
			return;
		}
		$worker_count = max( 1, (int) ( $config['worker_count'] ?? 1 ) );
		$batch_size   = max( 1, (int) ( $config['batch_size_per_worker'] ?? 3 ) );
		for ( $index = 1; $index <= $worker_count; $index++ ) {
			Worker::run( 'cron-' . $index, $batch_size );
		}
		if ( class_exists( QueueRunner::class ) ) {
			QueueRunner::run_next_batch( 3 );
		}
	}

	private static function ensure_cron_schedule(): void {
		$config   = QueueWorkerConfig::get();
		$interval = (string) ( $config['schedule_interval'] ?? 'five_minutes' );
		if ( 'manual' === $interval ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
			return;
		}
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $timestamp ) {
			wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
		}
	}
}
