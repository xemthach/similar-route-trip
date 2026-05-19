<?php
/**
 * Activation tasks.
 *
 * @package SimilarRouteTrip\Core
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Core;

use SimilarRouteTrip\Database\Installer;

defined( 'ABSPATH' ) || exit;

final class Activator {
	private const CRON_HOOK = 'srt_queue_cron';

	public static function activate(): void {
		Installer::create_or_update_table();
		update_option( 'srt_version', SRT_VERSION, false );
		update_option( 'srt_db_version', SRT_DB_VERSION, false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'five_minutes', self::CRON_HOOK );
		}
	}
}
