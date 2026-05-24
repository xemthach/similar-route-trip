<?php
/**
 * Activation tasks.
 *
 * @package SimilarRouteTrip\Core
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Core;

use SimilarRouteTrip\Database\Installer;
use SimilarRouteTrip\Queue\QueueWorkerConfig;

defined( 'ABSPATH' ) || exit;

final class Activator {
	private const CRON_HOOK = 'srt_queue_cron';

	public static function activate(): void {
		Installer::create_or_update_table();
		update_option( 'srt_version', SRT_VERSION, false );
		update_option( 'srt_db_version', SRT_DB_VERSION, false );
		$config = QueueWorkerConfig::get();
		$interval = (string) ( $config['schedule_interval'] ?? 'five_minutes' );
		if ( 'manual' !== $interval && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
		}
	}
}
