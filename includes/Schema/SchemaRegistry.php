<?php
/**
 * Route schema registry.
 *
 * @package SimilarRouteTrip\Schema
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Schema;

use SimilarRouteTrip\Database\RouteRepository;

defined( 'ABSPATH' ) || exit;

final class SchemaRegistry {

	public static function init(): void {
		add_action( 'wp_head', [ self::class, 'maybe_print_route_schema' ], 30 );
	}

	public static function maybe_print_route_schema(): void {
		$slug = get_query_var( 'srt_route', '' );
		if ( '' === $slug ) {
			$slug = isset( $_GET['srt_route'] ) ? sanitize_title( wp_unslash( $_GET['srt_route'] ) ) : '';
		}
		if ( '' === $slug ) {
			return;
		}

		$route = RouteRepository::get( $slug );
		if ( ! $route ) {
			return;
		}

		self::print_json_ld( self::service_schema( $route ) );
		$faq = self::faq_schema( $route );
		if ( $faq ) {
			self::print_json_ld( $faq );
		}
	}

	public static function service_schema( array $route ): array {
		$schema = [
			'@context'    => 'https://schema.org',
			'@type'       => 'TaxiService',
			'name'        => sprintf( 'Taxi %s di %s', $route['from_city'] ?? '', $route['to_city'] ?? '' ),
			'description' => $route['meta_description'] ?: sprintf(
				'Dich vu taxi %s di %s, khoang cach %s km, gia tu %s.',
				$route['from_city'] ?? '',
				$route['to_city'] ?? '',
				$route['distance_km'] ?? '',
				$route['price_display'] ?? ''
			),
			'areaServed'  => array_filter( [ $route['from_city'] ?? '', $route['to_city'] ?? '' ] ),
			'url'         => $route['landing_url'] ?? '',
		];

		if ( (int) ( $route['price_min'] ?? 0 ) > 0 ) {
			$schema['offers'] = [
				'@type'         => 'Offer',
				'price'         => (string) (int) $route['price_min'],
				'priceCurrency' => 'VND',
				'url'           => $route['booking_url'] ?? '',
			];
		}

		return (array) apply_filters( 'srt_schema_service', array_filter( $schema ), $route );
	}

	public static function faq_schema( array $route ): array {
		$faqs = json_decode( (string) ( $route['faqs_json'] ?? '[]' ), true );
		if ( ! is_array( $faqs ) || empty( $faqs ) ) {
			return [];
		}

		$entities = [];
		foreach ( $faqs as $faq ) {
			if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) {
				continue;
			}
			$entities[] = [
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( (string) $faq['question'] ),
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( (string) $faq['answer'] ),
				],
			];
		}

		if ( empty( $entities ) ) {
			return [];
		}

		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		];
	}

	private static function print_json_ld( array $schema ): void {
		if ( empty( $schema ) ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}
