<?php
/**
 * Deactivation tasks.
 *
 * @package SimilarRouteTrip\Core
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	private const CRON_HOOK = 'srt_queue_cron';

	public static function deactivate(): void {
		global $wpdb;

		wp_clear_scheduled_hook( self::CRON_HOOK );
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_srt_%'
			    OR option_name LIKE '_transient_timeout_srt_%'"
		);
	}
}
