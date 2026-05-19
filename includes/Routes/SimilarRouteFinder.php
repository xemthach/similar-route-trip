<?php
/**
 * Similar route finder.
 *
 * @package SimilarRouteTrip\Routes
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Routes;

use SimilarRouteTrip\Database\RouteRepository;

defined( 'ABSPATH' ) || exit;

final class SimilarRouteFinder {

	public static function find( string $slug, int $limit = 6 ): array {
		$route = RouteRepository::get( $slug );
		if ( ! $route ) {
			return [];
		}

		$candidates = RouteRepository::all( [ 'active' => true, 'limit' => 200 ] );
		$scored     = [];

		foreach ( $candidates as $candidate ) {
			if ( (string) $candidate['slug'] === (string) $route['slug'] ) {
				continue;
			}

			$score = 0;
			if ( (string) $candidate['from_slug'] === (string) $route['from_slug'] ) {
				$score += 60;
			}
			if ( (string) $candidate['to_slug'] === (string) $route['to_slug'] ) {
				$score += 50;
			}

			$distance_gap = abs( (float) $candidate['distance_km'] - (float) $route['distance_km'] );
			$score       += max( 0, 30 - (int) $distance_gap );

			$price_gap = abs( (int) $candidate['price_min'] - (int) $route['price_min'] );
			$score    += max( 0, 20 - (int) floor( $price_gap / 100000 ) );

			$candidate['_similarity_score'] = $score;
			$scored[] = $candidate;
		}

		usort(
			$scored,
			static fn( array $a, array $b ): int => ( (int) $b['_similarity_score'] <=> (int) $a['_similarity_score'] )
		);

		return array_slice( $scored, 0, max( 1, min( 20, $limit ) ) );
	}
}
