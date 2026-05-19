<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Routes;

use SimilarRouteTrip\Database\RouteRepository;

defined( 'ABSPATH' ) || exit;

final class RouteDuplicateDetector {
	public static function check( string $from, string $to, bool $reverse = false ): array {
		$slug = RouteNormalizer::build_slug( $from, $to );
		$found = [];
		if ( RouteRepository::get( $slug ) ) {
			$found[] = [ 'type' => 'same_slug', 'slug' => $slug ];
		}
		if ( $reverse ) {
			$reverse_slug = RouteNormalizer::build_slug( $to, $from );
			if ( RouteRepository::get( $reverse_slug ) ) {
				$found[] = [ 'type' => 'reverse_slug', 'slug' => $reverse_slug ];
			}
		}
		return $found;
	}
}
