<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Routes;

use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Pricing\DistanceCalculatorAdapter;
use SimilarRouteTrip\Booking\BookingLinkBuilder;
use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

final class RouteCreator {
	public static function preview( string $from, array $to_locations, array $args = [] ): array {
		$routes = [];
		foreach ( $to_locations as $to ) {
			$to = trim( (string) $to );
			if ( '' === trim( $from ) || '' === $to ) {
				continue;
			}
			$distance = (float) ( $args['distance_km'] ?? 0 );
			$price    = (int) ( $args['price_min'] ?? 0 );
			if ( $price <= 0 && $distance > 0 ) {
				$price = (int) round( $distance * DistanceCalculatorAdapter::cheapest_rate() );
			}
			$slug = RouteNormalizer::build_slug( $from, $to );
			$data = [
				'slug'                => $slug,
				'from_city'           => trim( $from ),
				'to_city'             => $to,
				'from_slug'           => RouteNormalizer::slugify( $from ),
				'to_slug'             => RouteNormalizer::slugify( $to ),
				'distance_km'         => $distance,
				'duration_min'        => (int) ( $args['duration_min'] ?? 0 ),
				'price_min'           => $price,
				'price_display'       => $price > 0 ? DistanceCalculatorAdapter::format_vnd( $price ) : '',
				'vehicle_prices_json' => DistanceCalculatorAdapter::vehicle_price_matrix( $distance ),
				'intro'               => '',
				'meta_title'          => '',
				'meta_description'    => '',
				'faqs_json'           => [],
				'reviews_json'        => [],
				'schema_json'         => [],
				'icon_type'           => 'library',
				'icon_value'          => 'map-pin',
				'landing_url'         => home_url( '/xe-' . $slug . '/' ),
				'source'              => 'manual',
				'source_ref'          => '',
				'is_active'           => 1,
				'sort_order'          => 0,
				'duplicates'          => RouteDuplicateDetector::check( $from, $to, ! empty( $args['detect_reverse'] ) ),
			];
			$data['booking_url'] = BookingLinkBuilder::for_route( $data );
			$routes[] = $data;
		}
		return $routes;
	}

	public static function bulk_create( string $from, array $to_locations, array $args = [] ): array {
		$preview = self::preview( $from, $to_locations, $args );
		$created = 0;
		$skipped = 0;
		$errors  = [];
		foreach ( $preview as $route ) {
			if ( ! empty( $route['duplicates'] ) && empty( $args['overwrite'] ) ) {
				$skipped++;
				continue;
			}
			unset( $route['duplicates'] );
			if ( RouteRepository::upsert( $route ) ) {
				$created++;
				LogRepository::add( 'info', 'route_created', 'Route created.', [ 'route_id' => 0, 'slug' => $route['slug'] ] );
			} else {
				$errors[] = $route['slug'];
			}
		}
		return [ 'previewed' => count( $preview ), 'created' => $created, 'skipped' => $skipped, 'errors' => $errors ];
	}
}
