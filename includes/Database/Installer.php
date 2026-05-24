<?php
/**
 * Database installer.
 *
 * @package SimilarRouteTrip\Database
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Database;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'srt_db_version', '0' );
		if ( version_compare( $installed, SRT_DB_VERSION, '<' ) ) {
			self::create_or_update_table();
			update_option( 'srt_db_version', SRT_DB_VERSION, false );
		}
		update_option( 'srt_version', SRT_VERSION, false );
	}

	public static function create_or_update_table(): void {
		global $wpdb;

		$table           = RouteRepository::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL,
			from_city varchar(120) NOT NULL,
			to_city varchar(120) NOT NULL,
			from_slug varchar(120) NOT NULL,
			to_slug varchar(120) NOT NULL,
			distance_km decimal(8,2) NOT NULL DEFAULT 0,
			duration_min smallint unsigned NOT NULL DEFAULT 0,
			price_min int unsigned NOT NULL DEFAULT 0,
			price_display varchar(80) NOT NULL DEFAULT '',
			vehicle_prices_json longtext NOT NULL,
			intro longtext NOT NULL,
			meta_title varchar(255) NOT NULL DEFAULT '',
			meta_description varchar(320) NOT NULL DEFAULT '',
			faqs_json longtext NOT NULL,
			reviews_json longtext NOT NULL,
			schema_json longtext NOT NULL,
			icon_type varchar(30) NOT NULL DEFAULT '',
			icon_value varchar(120) NOT NULL DEFAULT '',
			booking_url varchar(500) NOT NULL DEFAULT '',
			landing_url varchar(500) NOT NULL DEFAULT '',
			source varchar(50) NOT NULL DEFAULT '',
			source_ref varchar(191) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order smallint unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_status varchar(20) NOT NULL DEFAULT '',
			generated_at datetime NULL DEFAULT NULL,
			last_generated_at datetime NULL DEFAULT NULL,
			ai_config_source varchar(50) NOT NULL DEFAULT '',
			content_hash varchar(64) NOT NULL DEFAULT '',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ai_status varchar(30) NOT NULL DEFAULT '',
			ai_error text NULL,
			last_synced_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY from_slug (from_slug),
			KEY to_slug (to_slug),
			KEY source (source),
			KEY is_active (is_active),
			KEY post_id (post_id),
			KEY ai_config_source (ai_config_source),
			KEY ai_status (ai_status),
			KEY price_min (price_min),
			KEY distance_km (distance_km)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::create_queue_table();
		self::create_jobs_table();
		self::create_logs_table();
	}

	private static function create_queue_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'srt_queue';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			route_id bigint(20) unsigned NOT NULL DEFAULT 0,
			task_type varchar(50) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'pending',
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint unsigned NOT NULL DEFAULT 3,
			payload_json longtext NOT NULL,
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY route_id (route_id),
			KEY task_type (task_type),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	private static function create_logs_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'srt_logs';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(20) NOT NULL DEFAULT 'info',
			event varchar(80) NOT NULL DEFAULT '',
			route_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(50) NOT NULL DEFAULT '',
			message text NULL,
			context_json longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY event (event),
			KEY route_id (route_id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	private static function create_jobs_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'srt_jobs';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(50) NOT NULL DEFAULT '',
			route_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			topic varchar(80) NOT NULL DEFAULT '',
			content_length varchar(40) NOT NULL DEFAULT '',
			payload_json longtext NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			priority smallint unsigned NOT NULL DEFAULT 10,
			worker_id varchar(80) NOT NULL DEFAULT '',
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint unsigned NOT NULL DEFAULT 3,
			locked_at datetime NULL DEFAULT NULL,
			available_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at datetime NULL DEFAULT NULL,
			finished_at datetime NULL DEFAULT NULL,
			error_message text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY job_type (job_type),
			KEY route_id (route_id),
			KEY available_at (available_at),
			KEY priority (priority)
		) {$charset_collate};";
		dbDelta( $sql );
	}
}
