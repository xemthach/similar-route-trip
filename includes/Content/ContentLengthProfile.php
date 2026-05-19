<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

defined( 'ABSPATH' ) || exit;

final class ContentLengthProfile {
	public static function profiles(): array {
		return [
			'short' => [ 'min' => 700, 'max' => 900 ],
			'standard' => [ 'min' => 1000, 'max' => 1400 ],
			'long' => [ 'min' => 1600, 'max' => 2200 ],
			'deep' => [ 'min' => 2500, 'max' => 3500 ],
		];
	}

	public static function resolve( string $length, int $custom_min = 0, int $custom_max = 0 ): array {
		$length = sanitize_key( $length );
		if ( 'custom' === $length ) {
			$min = max( 500, min( 10000, $custom_min ) );
			$max = max( $min, min( 12000, $custom_max ) );
			return [ 'id' => 'custom', 'min' => $min, 'max' => $max ];
		}
		$profiles = self::profiles();
		$selected = $profiles[ $length ] ?? $profiles['standard'];
		return [ 'id' => array_key_exists( $length, $profiles ) ? $length : 'standard', 'min' => $selected['min'], 'max' => $selected['max'] ];
	}
}

