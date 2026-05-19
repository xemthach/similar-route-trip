<?php
/**
 * Booking URL builder.
 *
 * @package SimilarRouteTrip\Booking
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Booking;

defined( 'ABSPATH' ) || exit;

final class BookingLinkBuilder {

	public static function for_route( array $route ): string {
		$base = apply_filters( 'srt_booking_base_url', home_url( '/dat-xe/' ), $route );

		return add_query_arg(
			[
				'route_slug' => $route['slug'] ?? '',
				'from'       => $route['from_city'] ?? '',
				'to'         => $route['to_city'] ?? '',
			],
			$base
		);
	}
}
