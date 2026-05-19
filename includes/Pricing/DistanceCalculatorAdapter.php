<?php
/**
 * Read-only bridge to Distance Calculator Map pricing data.
 *
 * @package SimilarRouteTrip\Pricing
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Pricing;

defined( 'ABSPATH' ) || exit;

final class DistanceCalculatorAdapter {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function vehicle_types(): array {
		if ( class_exists( '\DCM_Pricing' ) ) {
			$types = \DCM_Pricing::get_vehicle_types();
			return is_array( $types ) ? array_values( $types ) : [];
		}

		$types = get_option( 'dcm_vehicle_types', [] );
		return is_array( $types ) ? array_values( $types ) : [];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function service_types(): array {
		if ( class_exists( '\DCM_Pricing' ) ) {
			$types = \DCM_Pricing::get_service_types();
			return is_array( $types ) ? array_values( $types ) : [];
		}

		$types = get_option( 'dcm_service_types', [] );
		return is_array( $types ) ? array_values( $types ) : [];
	}

	public static function cheapest_rate(): float {
		$rates = [];
		foreach ( self::vehicle_types() as $vehicle ) {
			$rate = (float) ( $vehicle['price_per_km'] ?? 0 );
			if ( $rate > 0 ) {
				$rates[] = $rate;
			}
		}

		return $rates ? min( $rates ) : 0.0;
	}

	/**
	 * Build a per-vehicle price matrix for one route.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function vehicle_price_matrix( float $distance_km, string $service_id = 'standard', int $passengers = 1 ): array {
		$matrix = [];
		foreach ( self::vehicle_types() as $vehicle ) {
			$vehicle_id = (string) ( $vehicle['id'] ?? '' );
			if ( '' === $vehicle_id ) {
				continue;
			}

			if ( class_exists( '\DCM_Pricing' ) ) {
				$result = \DCM_Pricing::calculate_price( $distance_km, $vehicle_id, $service_id, $passengers );
				if ( is_array( $result ) && ! empty( $result['success'] ) ) {
					$matrix[] = [
						'vehicle_id'   => $vehicle_id,
						'vehicle_name' => (string) ( $vehicle['name'] ?? $vehicle_id ),
						'price'        => (int) ( $result['final_price'] ?? 0 ),
						'display'      => (string) ( $result['formatted_final_price'] ?? self::format_vnd( (int) ( $result['final_price'] ?? 0 ) ) ),
						'raw'          => $result,
					];
					continue;
				}
			}

			$price = (int) round( $distance_km * (float) ( $vehicle['price_per_km'] ?? 0 ) + (float) ( $vehicle['extra_fee'] ?? 0 ) );
			$matrix[] = [
				'vehicle_id'   => $vehicle_id,
				'vehicle_name' => (string) ( $vehicle['name'] ?? $vehicle_id ),
				'price'        => $price,
				'display'      => self::format_vnd( $price ),
				'raw'          => [],
			];
		}

		usort(
			$matrix,
			static fn( array $a, array $b ): int => ( $a['price'] <=> $b['price'] )
		);

		return $matrix;
	}

	public static function format_vnd( int $price ): string {
		return number_format( max( 0, $price ), 0, ',', '.' ) . ' VND';
	}
}
