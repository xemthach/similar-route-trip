<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class UsageTracker {
	private const OPTION = 'srt_provider_usage';

	public static function increment_usage( string $provider_id, string $task_type ): void {
		if ( '' === $provider_id ) {
			return;
		}
		$usage = get_option( self::OPTION, [] );
		$usage = is_array( $usage ) ? $usage : [];
		$day   = gmdate( 'Y-m-d' );
		if ( empty( $usage[ $day ] ) || ! is_array( $usage[ $day ] ) ) {
			$usage[ $day ] = [];
		}
		$key = $task_type . ':' . $provider_id;
		$usage[ $day ][ $key ] = (int) ( $usage[ $day ][ $key ] ?? 0 ) + 1;
		self::trim_old_days( $usage );
		update_option( self::OPTION, $usage, false );
	}

	public static function get_daily_usage( string $provider_id, string $task_type ): int {
		$usage = get_option( self::OPTION, [] );
		$usage = is_array( $usage ) ? $usage : [];
		$day   = gmdate( 'Y-m-d' );
		$key   = $task_type . ':' . $provider_id;
		return (int) ( $usage[ $day ][ $key ] ?? 0 );
	}

	public static function check_limit( string $provider_id, string $task_type, int $daily_limit ): bool {
		if ( $daily_limit <= 0 ) {
			return true;
		}
		return self::get_daily_usage( $provider_id, $task_type ) < $daily_limit;
	}

	private static function trim_old_days( array &$usage ): void {
		if ( count( $usage ) <= 7 ) {
			return;
		}
		ksort( $usage );
		while ( count( $usage ) > 7 ) {
			array_shift( $usage );
		}
	}
}
