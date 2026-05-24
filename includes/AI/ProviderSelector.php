<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class ProviderSelector {
	private const CURSOR_OPTION = 'srt_provider_selector_cursor';

	public static function ordered_candidates( array $providers, string $task_type ): array {
		$filtered = [];
		foreach ( $providers as $provider ) {
			$provider_id = (string) ( $provider['id'] ?? '' );
			if ( '' === $provider_id || empty( $provider['enabled'] ) ) {
				continue;
			}
			if ( ! UsageTracker::check_limit( $provider_id, $task_type, (int) ( $provider['daily_limit'] ?? 0 ) ) ) {
				continue;
			}
			if ( ProviderHealthManager::in_cooldown( $provider_id ) ) {
				continue;
			}
			$filtered[] = $provider;
		}

		usort(
			$filtered,
			static function ( array $a, array $b ): int {
				$priority_compare = (int) ( $a['priority'] ?? 10 ) <=> (int) ( $b['priority'] ?? 10 );
				if ( 0 !== $priority_compare ) {
					return $priority_compare;
				}
				return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		if ( empty( $filtered ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $filtered as $provider ) {
			$grouped[ (int) ( $provider['priority'] ?? 10 ) ][] = $provider;
		}
		ksort( $grouped );

		$ordered = [];
		foreach ( $grouped as $priority => $group ) {
			$weighted = [];
			foreach ( $group as $provider ) {
				for ( $i = 0; $i < max( 1, (int) ( $provider['weight'] ?? 1 ) ); $i++ ) {
					$weighted[] = $provider;
				}
			}
			$cursor = self::next_cursor( $task_type . ':' . $priority, count( $weighted ) );
			for ( $i = 0, $count = count( $weighted ); $i < $count; $i++ ) {
				$provider = $weighted[ ( $cursor + $i ) % $count ];
				$key = (string) ( $provider['id'] ?? '' );
				if ( '' !== $key && ! isset( $ordered[ $key ] ) ) {
					$ordered[ $key ] = $provider;
				}
			}
		}

		return array_values( $ordered );
	}

	private static function next_cursor( string $bucket, int $count ): int {
		if ( $count <= 1 ) {
			return 0;
		}
		$cursors = get_option( self::CURSOR_OPTION, [] );
		$cursors = is_array( $cursors ) ? $cursors : [];
		$current = (int) ( $cursors[ $bucket ] ?? 0 );
		$cursors[ $bucket ] = $current + 1;
		update_option( self::CURSOR_OPTION, $cursors, false );
		return $current % $count;
	}
}
