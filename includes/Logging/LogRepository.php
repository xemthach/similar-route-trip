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
		$context = self::redact_context( $context );
		$message = self::redact_sensitive_text( $message );
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

	private static function redact_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$context[ $key ] = self::redact_sensitive_text( (string) $value );
		}
		return $context;
	}

	private static function redact_sensitive_text( string $text ): string {
		$text = preg_replace( '/\bsk-[A-Za-z0-9_-]{12,}\b/', '[redacted]', $text ) ?? $text;
		$text = preg_replace( '/\bBearer\s+[A-Za-z0-9._-]{12,}\b/i', 'Bearer [redacted]', $text ) ?? $text;
		$text = preg_replace( '/\b(api[_-]?key|access[_-]?token|authorization)\s*[:=]\s*[A-Za-z0-9._-]{8,}\b/i', '$1=[redacted]', $text ) ?? $text;
		return $text;
	}

	public static function latest( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, $limit ) ) ),
			ARRAY_A
		) ?: [];
	}
}
