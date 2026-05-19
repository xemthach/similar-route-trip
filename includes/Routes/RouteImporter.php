<?php
/**
 * Import routes from legacy sources.
 *
 * @package SimilarRouteTrip\Routes
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Routes;

use SimilarRouteTrip\Database\RouteRepository;

defined( 'ABSPATH' ) || exit;

final class RouteImporter {

	public static function import_theme_options(): array {
		$options = get_option( 'flavormt_theme_options', [] );
		$routes  = is_array( $options ) && isset( $options['routes'] ) && is_array( $options['routes'] )
			? $options['routes']
			: [];

		return self::import_rows( $routes, 'theme' );
	}

	public static function import_taxi_route_engine(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'taxi_routes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [
				'source'   => 'taxi_route_engine',
				'found'    => 0,
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => [ 'Table not found: ' . $table ],
			];
		}

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ) ?: [];
		return self::import_rows( $rows, 'tre' );
	}

	public static function sync_prices(): array {
		$routes   = RouteRepository::all( [ 'active' => false, 'limit' => 200 ] );
		$updated  = 0;
		$errors   = [];

		foreach ( $routes as $route ) {
			$data = RouteNormalizer::from_tre_row(
				[
					'id'            => $route['source_ref'] ?: $route['id'],
					'slug'          => $route['slug'],
					'from_city'     => $route['from_city'],
					'to_city'       => $route['to_city'],
					'from_slug'     => $route['from_slug'],
					'to_slug'       => $route['to_slug'],
					'distance_km'   => $route['distance_km'],
					'duration_min'  => $route['duration_min'],
					'price_min'     => $route['price_min'],
					'price_display' => $route['price_display'],
					'link_url'      => $route['landing_url'],
					'meta_desc'     => $route['meta_description'],
					'faqs_json'     => $route['faqs_json'],
					'reviews_json'  => $route['reviews_json'],
					'is_active'     => $route['is_active'],
					'sort_order'    => $route['sort_order'],
				]
			);

			if ( ! $data || ! RouteRepository::upsert( array_merge( $route, $data, [ 'source' => $route['source'] ] ) ) ) {
				$errors[] = 'Price sync failed: ' . ( $route['slug'] ?? '' );
				continue;
			}
			$updated++;
		}

		return [
			'source'  => 'distance_calculator_map',
			'updated' => $updated,
			'errors'  => $errors,
		];
	}

	private static function import_rows( array $rows, string $source ): array {
		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $rows as $index => $row ) {
			$data = 'tre' === $source
				? RouteNormalizer::from_tre_row( (array) $row, (int) $index )
				: RouteNormalizer::from_theme_route( (array) $row, (int) $index );

			if ( null === $data ) {
				$skipped++;
				continue;
			}

			if ( RouteRepository::upsert( $data ) ) {
				$imported++;
			} else {
				$errors[] = 'Import failed: ' . $data['slug'];
			}
		}

		return [
			'source'   => $source,
			'found'    => count( $rows ),
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}
}
