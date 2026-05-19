<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Logging;

defined( 'ABSPATH' ) || exit;

final class LogRepository {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'srt_logs';
	}

	public static function add( string $level, string $event, string $message = '', array $context = [] ): void {
		global $wpdb;
		unset( $context['api_key'], $context['key'], $context['authorization'] );
		$wpdb->insert(
			self::table(),
			[
				'level'        => sanitize_key( $level ),
				'event'        => sanitize_key( $event ),
				'route_id'     => (int) ( $context['route_id'] ?? 0 ),
				'post_id'      => (int) ( $context['post_id'] ?? 0 ),
				'provider'     => sanitize_key( (string) ( $context['provider'] ?? '' ) ),
				'message'      => sanitize_textarea_field( $message ),
				'context_json' => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	public static function latest( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, $limit ) ) ),
			ARRAY_A
		) ?: [];
	}
}
