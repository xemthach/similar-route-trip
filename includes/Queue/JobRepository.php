<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

defined( 'ABSPATH' ) || exit;

final class JobRepository {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'srt_jobs';
	}

	public static function enqueue( string $job_type, int $route_id, array $payload = [], array $args = [] ): int {
		global $wpdb;
		$inserted = $wpdb->insert(
			self::table(),
			[
				'job_type'      => sanitize_key( $job_type ),
				'route_id'      => $route_id,
				'post_id'       => (int) ( $payload['post_id'] ?? 0 ),
				'topic'         => sanitize_key( (string) ( $payload['topic'] ?? '' ) ),
				'content_length'=> sanitize_key( (string) ( $payload['content_length'] ?? '' ) ),
				'payload_json'  => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'        => 'pending',
				'priority'      => max( 1, min( 100, (int) ( $args['priority'] ?? 10 ) ) ),
				'attempts'      => 0,
				'max_attempts'  => max( 1, min( 5, (int) ( $args['max_attempts'] ?? 3 ) ) ),
				'available_at'  => current_time( 'mysql' ),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
		);
		return false !== $inserted ? (int) $wpdb->insert_id : 0;
	}

	public static function enqueue_bulk( array $jobs ): int {
		$count = 0;
		foreach ( $jobs as $job ) {
			if ( empty( $job['job_type'] ) ) {
				continue;
			}
			$job_id = self::enqueue(
				(string) $job['job_type'],
				(int) ( $job['route_id'] ?? 0 ),
				(array) ( $job['payload'] ?? [] ),
				(array) ( $job['args'] ?? [] )
			);
			if ( $job_id > 0 ) {
				$count++;
			}
		}
		return $count;
	}

	public static function next_jobs( int $limit, string $worker_id ): array {
		global $wpdb;
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','retrying') AND available_at <= %s ORDER BY priority ASC, id ASC LIMIT %d",
				current_time( 'mysql' ),
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		) ?: [];

		$locked = [];
		foreach ( $jobs as $job ) {
			if ( self::lock_job( (int) ( $job['id'] ?? 0 ), $worker_id ) ) {
				$locked[] = self::get( (int) ( $job['id'] ?? 0 ) );
			}
		}
		return array_values( array_filter( $locked ) );
	}

	public static function lock_job( int $id, string $worker_id ): bool {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'processing', worker_id = %s, locked_at = %s, started_at = COALESCE(started_at, %s), attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ('pending','retrying')",
				$worker_id,
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				$id
			)
		);
		return false !== $updated && $updated > 0;
	}

	public static function complete_job( int $id ): void {
		self::update_status( $id, 'completed', '' );
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'finished_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function fail_job( int $id, string $error, bool $retryable = true ): void {
		$job = self::get( $id );
		if ( ! $job ) {
			return;
		}
		$attempts = (int) ( $job['attempts'] ?? 0 );
		$max      = (int) ( $job['max_attempts'] ?? 1 );
		$status   = $retryable && $attempts < $max ? 'retrying' : 'failed';
		$available_at = 'retrying' === $status ? gmdate( 'Y-m-d H:i:s', time() + 120 ) : current_time( 'mysql' );
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'status'        => $status,
				'error_message' => sanitize_text_field( $error ),
				'available_at'  => $available_at,
				'finished_at'   => 'failed' === $status ? current_time( 'mysql' ) : null,
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function retry_job( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			[
				'status'        => 'pending',
				'error_message' => '',
				'locked_at'     => null,
				'worker_id'     => '',
				'available_at'  => current_time( 'mysql' ),
				'finished_at'   => null,
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function retry_failed(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET status = 'pending', error_message = '', locked_at = NULL, worker_id = '', available_at = %s, finished_at = NULL, updated_at = %s WHERE status IN ('failed','retrying')",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);
	}

	public static function cancel_job( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			[
				'status'      => 'cancelled',
				'finished_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function clear_completed(): int {
		global $wpdb;
		return (int) $wpdb->query( "DELETE FROM " . self::table() . " WHERE status = 'completed'" );
	}

	public static function stats(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) ?: [];
		$stats = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'retrying'   => 0,
			'cancelled'  => 0,
		];
		foreach ( $rows as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}
		$stats['total'] = array_sum( $stats );
		return $stats;
	}

	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 100, $limit ) ) ),
			ARRAY_A
		) ?: [];
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function release_stuck_jobs( int $timeout_seconds = 1800 ): int {
		global $wpdb;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " 
				 SET status = 'pending', 
				     error_message = 'Job timed out or worker crashed. Automatically released.', 
				     locked_at = NULL, 
				     worker_id = '', 
				     updated_at = %s 
				 WHERE status = 'processing' AND locked_at <= %s",
				current_time( 'mysql' ),
				$threshold
			)
		);
	}

	private static function update_status( int $id, string $status, string $error = '' ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'status'        => $status,
				'error_message' => sanitize_text_field( $error ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}
}
