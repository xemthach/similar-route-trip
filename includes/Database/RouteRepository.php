<?php
/**
 * Route repository for wp_srt_routes.
 *
 * @package SimilarRouteTrip\Database
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Database;

defined( 'ABSPATH' ) || exit;

final class RouteRepository {

	private const SORTABLE = [
		'sort_order',
		'from_city',
		'to_city',
		'distance_km',
		'price_min',
		'updated_at',
		'slug',
	];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . SRT_TABLE_NAME;
	}

	public static function count( bool $active_only = true ): int {
		global $wpdb;
		$where = $active_only ? 'WHERE is_active = 1' : '';
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " {$where}" );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( array $args = [] ): array {
		global $wpdb;

		$orderby = self::sanitize_orderby( (string) ( $args['orderby'] ?? 'sort_order' ) );
		$order   = strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
		$active  = array_key_exists( 'active', $args ) ? (bool) $args['active'] : true;
		$limit   = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 100;

		$where = $active ? 'WHERE is_active = 1' : '';

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " {$where} ORDER BY {$orderby} {$order} LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	public static function get( string $slug ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', sanitize_title( $slug ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	public static function upsert( array $data ): bool {
		global $wpdb;

		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return false;
		}

		$row = [
			'slug'                => $slug,
			'from_city'           => sanitize_text_field( (string) ( $data['from_city'] ?? '' ) ),
			'to_city'             => sanitize_text_field( (string) ( $data['to_city'] ?? '' ) ),
			'from_slug'           => sanitize_title( (string) ( $data['from_slug'] ?? '' ) ),
			'to_slug'             => sanitize_title( (string) ( $data['to_slug'] ?? '' ) ),
			'distance_km'         => (float) ( $data['distance_km'] ?? 0 ),
			'duration_min'        => (int) ( $data['duration_min'] ?? 0 ),
			'price_min'           => (int) ( $data['price_min'] ?? 0 ),
			'price_display'       => sanitize_text_field( (string) ( $data['price_display'] ?? '' ) ),
			'vehicle_prices_json' => self::sanitize_json( $data['vehicle_prices_json'] ?? '[]' ),
			'intro'               => wp_kses_post( (string) ( $data['intro'] ?? '' ) ),
			'meta_title'          => sanitize_text_field( (string) ( $data['meta_title'] ?? '' ) ),
			'meta_description'    => sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) ),
			'faqs_json'           => self::sanitize_json( $data['faqs_json'] ?? '[]' ),
			'reviews_json'        => self::sanitize_json( $data['reviews_json'] ?? '[]' ),
			'schema_json'         => self::sanitize_json( $data['schema_json'] ?? '[]' ),
			'icon_type'           => sanitize_key( (string) ( $data['icon_type'] ?? '' ) ),
			'icon_value'          => sanitize_text_field( (string) ( $data['icon_value'] ?? '' ) ),
			'booking_url'         => esc_url_raw( (string) ( $data['booking_url'] ?? '' ) ),
			'landing_url'         => esc_url_raw( (string) ( $data['landing_url'] ?? '' ) ),
			'source'              => sanitize_key( (string) ( $data['source'] ?? '' ) ),
			'source_ref'          => sanitize_text_field( (string) ( $data['source_ref'] ?? '' ) ),
			'is_active'           => (int) ( $data['is_active'] ?? 1 ),
			'sort_order'          => (int) ( $data['sort_order'] ?? 0 ),
			'post_id'             => (int) ( $data['post_id'] ?? 0 ),
			'post_status'         => sanitize_key( (string) ( $data['post_status'] ?? '' ) ),
			'generated_at'        => self::sanitize_datetime( $data['generated_at'] ?? null ),
			'last_generated_at'   => self::sanitize_datetime( $data['last_generated_at'] ?? null ),
			'ai_config_source'    => sanitize_key( (string) ( $data['ai_config_source'] ?? '' ) ),
			'content_hash'        => sanitize_text_field( (string) ( $data['content_hash'] ?? '' ) ),
			'image_id'            => (int) ( $data['image_id'] ?? 0 ),
			'ai_status'           => sanitize_key( (string) ( $data['ai_status'] ?? '' ) ),
			'ai_error'            => sanitize_textarea_field( (string) ( $data['ai_error'] ?? '' ) ),
			'last_synced_at'      => current_time( 'mysql' ),
		];

		$formats = [
			'%s', '%s', '%s', '%s', '%s',
			'%f', '%d', '%d', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%s', '%s', '%s',
			'%s', '%s', '%d', '%d', '%d',
			'%s', '%s', '%s', '%s', '%d',
			'%s', '%s', '%s', '%s',
		];

		$exists = self::get( $slug );
		if ( $exists ) {
			return false !== $wpdb->update( self::table(), $row, [ 'slug' => $slug ], $formats, [ '%s' ] );
		}

		$row['created_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		return false !== $wpdb->insert( self::table(), $row, $formats );
	}

	private static function sanitize_json( $value ): string {
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return '[]';
		}

		return (string) wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function sanitize_orderby( string $column ): string {
		return in_array( $column, self::SORTABLE, true ) ? $column : 'sort_order';
	}

	private static function sanitize_datetime( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		$time = strtotime( (string) $value );
		return $time ? gmdate( 'Y-m-d H:i:s', $time + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : null;
	}

	public static function get_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function update_generation_meta( int $route_id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'post_id', 'post_status', 'generated_at', 'last_generated_at', 'ai_config_source', 'content_hash', 'image_id', 'ai_status', 'ai_error' ];
		$row     = [];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ];
			}
		}
		if ( empty( $row ) ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), $row, [ 'id' => $route_id ] );
	}
}
