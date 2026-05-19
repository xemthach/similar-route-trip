<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

use SimilarRouteTrip\Routes\SimilarRouteFinder;

defined( 'ABSPATH' ) || exit;

final class PlaceholderResolver {
	public static function resolve( string $template, array $route, array $context = [] ): string {
		$similar = SimilarRouteFinder::find( (string) $route['slug'], 5 );
		$vehicle_prices = json_decode( (string) ( $route['vehicle_prices_json'] ?? '[]' ), true );
		$map = [
			'{{route.from}}'            => (string) ( $route['from_city'] ?? '' ),
			'{{route.to}}'              => (string) ( $route['to_city'] ?? '' ),
			'{{route.slug}}'            => (string) ( $route['slug'] ?? '' ),
			'{{route.distance}}'        => (string) ( $route['distance_km'] ?? '' ),
			'{{route.duration}}'        => (string) ( $route['duration_min'] ?? '' ),
			'{{route.price}}'           => (string) ( $route['price_min'] ?? '' ),
			'{{route.formatted_price}}' => (string) ( $route['price_display'] ?? '' ),
			'{{route.vehicle_prices}}'  => wp_json_encode( is_array( $vehicle_prices ) ? $vehicle_prices : [], JSON_UNESCAPED_UNICODE ),
			'{{route.similar_routes}}'  => implode( ', ', array_map( static fn( $r ) => ( $r['from_city'] ?? '' ) . ' di ' . ( $r['to_city'] ?? '' ), $similar ) ),
			'{{site.name}}'             => get_bloginfo( 'name' ),
			'{{site.phone}}'            => (string) get_option( 'admin_email', '' ),
			'{{site.service_area}}'     => get_bloginfo( 'description' ),
			'{{topic.id}}'              => (string) ( $context['topic_id'] ?? 'route_landing' ),
			'{{topic.label}}'           => (string) ( $context['topic_label'] ?? 'Route Landing' ),
			'{{content.length}}'        => (string) ( $context['content_length'] ?? 'standard' ),
			'{{content.min_words}}'     => (string) ( $context['min_words'] ?? '' ),
			'{{content.max_words}}'     => (string) ( $context['max_words'] ?? '' ),
			'{{seo.primary_keyword}}'   => (string) ( $context['primary_keyword'] ?? '' ),
			'{{seo.secondary_keywords}}'=> (string) ( $context['secondary_keywords'] ?? '' ),
			'{{seo.search_intent}}'     => (string) ( $context['search_intent'] ?? '' ),
		];
		return strtr( $template, $map );
	}
}
