<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class ProviderHealthManager {
	private const OPTION = 'srt_provider_health';

	public static function get( string $provider_id ): array {
		$health = get_option( self::OPTION, [] );
		$health = is_array( $health ) ? $health : [];
		return (array) ( $health[ $provider_id ] ?? [] );
	}

	public static function mark_success( string $provider_id ): void {
		if ( '' === $provider_id ) {
			return;
		}
		$health = get_option( self::OPTION, [] );
		$health = is_array( $health ) ? $health : [];
		$health[ $provider_id ] = [
			'last_success_at' => current_time( 'mysql' ),
			'cooldown_until'  => '',
			'last_error'      => '',
			'failure_count'   => 0,
		];
		update_option( self::OPTION, $health, false );
	}

	public static function mark_failure( string $provider_id, string $error, int $cooldown_minutes = 15 ): void {
		if ( '' === $provider_id ) {
			return;
		}
		$health = get_option( self::OPTION, [] );
		$health = is_array( $health ) ? $health : [];
		$entry  = (array) ( $health[ $provider_id ] ?? [] );
		$health[ $provider_id ] = [
			'last_success_at' => (string) ( $entry['last_success_at'] ?? '' ),
			'cooldown_until'  => gmdate( 'Y-m-d H:i:s', time() + max( 0, $cooldown_minutes ) * MINUTE_IN_SECONDS ),
			'last_error'      => sanitize_text_field( $error ),
			'failure_count'   => (int) ( $entry['failure_count'] ?? 0 ) + 1,
		];
		update_option( self::OPTION, $health, false );
	}

	public static function in_cooldown( string $provider_id ): bool {
		$cooldown_until = (string) ( self::get( $provider_id )['cooldown_until'] ?? '' );
		if ( '' === $cooldown_until ) {
			return false;
		}
		return strtotime( $cooldown_until ) > time();
	}
}
