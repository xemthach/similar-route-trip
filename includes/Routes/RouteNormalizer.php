<?php
/**
 * Normalize route data from legacy sources.
 *
 * @package SimilarRouteTrip\Routes
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Routes;

use SimilarRouteTrip\Booking\BookingLinkBuilder;
use SimilarRouteTrip\Pricing\DistanceCalculatorAdapter;

defined( 'ABSPATH' ) || exit;

final class RouteNormalizer {

	public static function from_theme_route( array $route, int $index = 0 ): ?array {
		$from = trim( (string) ( $route['from'] ?? '' ) );
		$to   = trim( (string) ( $route['to'] ?? '' ) );
		if ( '' === $from || '' === $to ) {
			return null;
		}

		$distance = self::parse_distance( (string) ( $route['distance'] ?? '' ) );
		$duration = self::parse_duration( (string) ( $route['duration'] ?? '' ) );
		$price    = self::parse_price( (string) ( $route['price_from'] ?? '' ) );

		if ( $price <= 0 && $distance > 0 ) {
			$price = (int) round( $distance * DistanceCalculatorAdapter::cheapest_rate() );
		}

		$slug = self::build_slug( $from, $to );
		$data = [
			'slug'                => $slug,
			'from_city'           => $from,
			'to_city'             => $to,
			'from_slug'           => self::slugify( $from ),
			'to_slug'             => self::slugify( $to ),
			'distance_km'         => $distance,
			'duration_min'        => $duration,
			'price_min'           => $price,
			'price_display'       => (string) ( $route['price_from'] ?? '' ),
			'vehicle_prices_json' => DistanceCalculatorAdapter::vehicle_price_matrix( $distance ),
			'intro'               => (string) ( $route['custom_intro'] ?? '' ),
			'meta_title'          => '',
			'meta_description'    => (string) ( $route['meta_description'] ?? '' ),
			'faqs_json'           => $route['faqs'] ?? [],
			'reviews_json'        => $route['reviews'] ?? [],
			'schema_json'         => [],
			'icon_type'           => (string) ( $route['icon_type'] ?? '' ),
			'icon_value'          => (string) ( $route['icon'] ?? '' ),
			'landing_url'         => self::legacy_landing_url( $from, $to ),
			'source'              => 'flavormt_theme_options',
			'source_ref'          => (string) $index,
			'is_active'           => 1,
			'sort_order'          => $index,
		];
		$data['booking_url'] = BookingLinkBuilder::for_route( $data );

		return $data;
	}

	public static function from_tre_row( array $row, int $index = 0 ): ?array {
		$from = trim( (string) ( $row['from_city'] ?? '' ) );
		$to   = trim( (string) ( $row['to_city'] ?? '' ) );
		if ( '' === $from || '' === $to ) {
			return null;
		}

		$distance = (float) ( $row['distance_km'] ?? 0 );
		$data     = [
			'slug'                => sanitize_title( (string) ( $row['slug'] ?? self::build_slug( $from, $to ) ) ),
			'from_city'           => $from,
			'to_city'             => $to,
			'from_slug'           => sanitize_title( (string) ( $row['from_slug'] ?? self::slugify( $from ) ) ),
			'to_slug'             => sanitize_title( (string) ( $row['to_slug'] ?? self::slugify( $to ) ) ),
			'distance_km'         => $distance,
			'duration_min'        => (int) ( $row['duration_min'] ?? 0 ),
			'price_min'           => (int) ( $row['price_min'] ?? 0 ),
			'price_display'       => (string) ( $row['price_display'] ?? '' ),
			'vehicle_prices_json' => DistanceCalculatorAdapter::vehicle_price_matrix( $distance ),
			'intro'               => (string) ( $row['custom_intro'] ?? '' ),
			'meta_title'          => '',
			'meta_description'    => (string) ( $row['meta_desc'] ?? '' ),
			'faqs_json'           => self::decode_json_or_empty( $row['faqs_json'] ?? '[]' ),
			'reviews_json'        => self::decode_json_or_empty( $row['reviews_json'] ?? '[]' ),
			'schema_json'         => [],
			'icon_type'           => (string) ( $row['icon_type'] ?? '' ),
			'icon_value'          => (string) ( $row['icon_value'] ?? '' ),
			'landing_url'         => (string) ( $row['link_url'] ?? self::legacy_landing_url( $from, $to ) ),
			'source'              => 'taxi_route_engine',
			'source_ref'          => (string) ( $row['id'] ?? $index ),
			'is_active'           => (int) ( $row['is_active'] ?? 1 ),
			'sort_order'          => (int) ( $row['sort_order'] ?? $index ),
		];
		$data['booking_url'] = BookingLinkBuilder::for_route( $data );

		return $data;
	}

	public static function build_slug( string $from, string $to ): string {
		return self::slugify( $from ) . '-di-' . self::slugify( $to );
	}

	public static function slugify( string $text ): string {
		return sanitize_title( remove_accents( $text ) );
	}

	private static function legacy_landing_url( string $from, string $to ): string {
		return home_url( '/xe-' . self::build_slug( $from, $to ) . '/' );
	}

	private static function parse_price( string $text ): int {
		$clean = preg_replace( '/[^0-9]/', '', $text );
		return '' === $clean ? 0 : (int) $clean;
	}

	private static function parse_distance( string $text ): float {
		if ( preg_match( '/[\d]+([.,]\d+)?/', $text, $m ) ) {
			return (float) str_replace( ',', '.', $m[0] );
		}
		return 0.0;
	}

	private static function parse_duration( string $text ): int {
		$lower = mb_strtolower( $text, 'UTF-8' );
		if ( preg_match( '/([\d]+[.,]?\d*)\s*(?:gio|h|giờ)/u', $lower, $m ) ) {
			return (int) round( (float) str_replace( ',', '.', $m[1] ) * 60 );
		}
		if ( preg_match( '/([\d]+)\s*(?:phut|phút|min)/u', $lower, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/([\d]+[.,]?\d*)/', $lower, $m ) ) {
			return (int) round( (float) str_replace( ',', '.', $m[1] ) * 60 );
		}
		return 0;
	}

	private static function decode_json_or_empty( $json ): array {
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
