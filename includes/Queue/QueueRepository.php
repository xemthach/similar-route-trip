<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

defined( 'ABSPATH' ) || exit;

final class QueueRepository {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'srt_queue';
	}

	public static function add( int $route_id, string $task_type, array $payload = [], int $max_attempts = 3 ): int {
		global $wpdb;
		$inserted = $wpdb->insert(
			self::table(),
			[
				'route_id'      => $route_id,
				'task_type'     => sanitize_key( $task_type ),
				'status'        => 'pending',
				'max_attempts'  => max( 1, min( 5, $max_attempts ) ),
				'payload_json'  => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
		return false !== $inserted ? (int) $wpdb->insert_id : 0;
	}

	public static function next( int $limit = 5 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','failed') AND attempts < max_attempts ORDER BY id ASC LIMIT %d", max( 1, min( 20, $limit ) ) ),
			ARRAY_A
		) ?: [];
	}

	public static function update_status( int $id, string $status, string $error = '' ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'status'        => sanitize_key( $status ),
				'error_message' => sanitize_textarea_field( $error ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
	}

	public static function increment_attempts( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET attempts = attempts + 1, updated_at = %s WHERE id = %d', current_time( 'mysql' ), $id ) );
	}

	public static function clear_completed(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM " . self::table() . " WHERE status = 'completed'" );
	}

	public static function retry_failed(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = 'pending', error_message = '', updated_at = %s WHERE status = 'failed' AND attempts < max_attempts",
				current_time( 'mysql' )
			)
		);
	}

	public static function retry_item( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			[
				'status'        => 'pending',
				'attempts'      => 0,
				'error_message' => '',
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function stats(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) ?: [];
		$stats = [
			'pending'   => 0,
			'failed'    => 0,
			'completed' => 0,
			'other'     => 0,
		];
		foreach ( $rows as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$total  = (int) ( $row['total'] ?? 0 );
			if ( array_key_exists( $status, $stats ) ) {
				$stats[ $status ] = $total;
				continue;
			}
			$stats['other'] += $total;
		}
		$stats['total'] = array_sum( $stats );
		return $stats;
	}

	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ),
			ARRAY_A
		) ?: [];
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}
}
