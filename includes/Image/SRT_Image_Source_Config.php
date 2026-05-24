<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

use SimilarRouteTrip\AI\AIKeyVault;

defined( 'ABSPATH' ) || exit;

final class SRT_Image_Source_Config {
	public const OPTION = 'srt_image_source_settings';

	public static function defaults(): array {
		return [
			'unsplash_enabled'      => 0,
			'unsplash_access_key'   => '',
			'unsplash_orientation'  => 'landscape',
			'unsplash_order_by'     => 'relevant',
			'unsplash_content_filter' => 'low',
			'unsplash_color'        => '',
			'unsplash_credit_mode'  => 'caption',
			'pexels_enabled'        => 0,
			'pexels_api_key'        => '',
			'pexels_orientation'    => 'landscape',
			'pexels_size'           => 'medium',
			'pexels_color'          => '',
			'pexels_locale'         => 'en-US',
			'pixabay_enabled'       => 0,
			'pixabay_api_key'       => '',
			'pixabay_image_type'    => 'photo',
			'pixabay_orientation'   => 'horizontal',
			'pixabay_safesearch'    => 1,
			'pixabay_order'         => 'popular',
			'pixabay_category'      => '',
			'pixabay_colors'        => '',
			'pixabay_editors_choice'=> 0,
			'source_priority'       => [ 'ai', 'pexels', 'pixabay', 'placeholder', 'unsplash' ],
		];
	}

	public static function get(): array {
		$settings = get_option( self::OPTION, [] );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
		$settings['source_priority'] = self::sanitize_priority( $settings['source_priority'] ?? [] );
		return $settings;
	}

	public static function save( array $input ): void {
		$current = self::get();
		$data    = [
			'unsplash_enabled'      => ! empty( $input['unsplash_enabled'] ) ? 1 : 0,
			'unsplash_access_key'   => self::sanitize_secret( (string) ( $input['unsplash_access_key'] ?? '' ), (string) ( $current['unsplash_access_key'] ?? '' ) ),
			'unsplash_orientation'  => self::sanitize_enum( (string) ( $input['unsplash_orientation'] ?? 'landscape' ), [ 'landscape', 'portrait', 'squarish' ], 'landscape' ),
			'unsplash_order_by'     => self::sanitize_enum( (string) ( $input['unsplash_order_by'] ?? 'relevant' ), [ 'relevant', 'latest' ], 'relevant' ),
			'unsplash_content_filter' => self::sanitize_enum( (string) ( $input['unsplash_content_filter'] ?? 'low' ), [ 'low', 'high' ], 'low' ),
			'unsplash_color'        => self::sanitize_enum( (string) ( $input['unsplash_color'] ?? '' ), [ '', 'black_and_white', 'black', 'white', 'yellow', 'orange', 'red', 'purple', 'magenta', 'green', 'teal', 'blue' ], '' ),
			'unsplash_credit_mode'  => self::sanitize_enum( (string) ( $input['unsplash_credit_mode'] ?? 'caption' ), [ 'caption', 'description', 'none' ], 'caption' ),
			'pexels_enabled'        => ! empty( $input['pexels_enabled'] ) ? 1 : 0,
			'pexels_api_key'        => self::sanitize_secret( (string) ( $input['pexels_api_key'] ?? '' ), (string) ( $current['pexels_api_key'] ?? '' ) ),
			'pexels_orientation'    => self::sanitize_enum( (string) ( $input['pexels_orientation'] ?? 'landscape' ), [ 'landscape', 'portrait', 'square' ], 'landscape' ),
			'pexels_size'           => self::sanitize_enum( (string) ( $input['pexels_size'] ?? 'medium' ), [ 'large', 'medium', 'small' ], 'medium' ),
			'pexels_color'          => sanitize_text_field( (string) ( $input['pexels_color'] ?? '' ) ),
			'pexels_locale'         => sanitize_text_field( (string) ( $input['pexels_locale'] ?? 'en-US' ) ),
			'pixabay_enabled'       => ! empty( $input['pixabay_enabled'] ) ? 1 : 0,
			'pixabay_api_key'       => self::sanitize_secret( (string) ( $input['pixabay_api_key'] ?? '' ), (string) ( $current['pixabay_api_key'] ?? '' ) ),
			'pixabay_image_type'    => self::sanitize_enum( (string) ( $input['pixabay_image_type'] ?? 'photo' ), [ 'all', 'photo', 'illustration', 'vector' ], 'photo' ),
			'pixabay_orientation'   => self::sanitize_enum( (string) ( $input['pixabay_orientation'] ?? 'horizontal' ), [ 'all', 'horizontal', 'vertical' ], 'horizontal' ),
			'pixabay_safesearch'    => ! empty( $input['pixabay_safesearch'] ) ? 1 : 0,
			'pixabay_order'         => self::sanitize_enum( (string) ( $input['pixabay_order'] ?? 'popular' ), [ 'popular', 'latest' ], 'popular' ),
			'pixabay_category'      => self::sanitize_enum( (string) ( $input['pixabay_category'] ?? '' ), [ '', 'backgrounds', 'fashion', 'nature', 'science', 'education', 'feelings', 'health', 'people', 'religion', 'places', 'animals', 'industry', 'computer', 'food', 'sports', 'transportation', 'travel', 'buildings', 'business', 'music' ], '' ),
			'pixabay_colors'        => sanitize_text_field( (string) ( $input['pixabay_colors'] ?? '' ) ),
			'pixabay_editors_choice'=> ! empty( $input['pixabay_editors_choice'] ) ? 1 : 0,
			'source_priority'       => self::sanitize_priority( $input['source_priority'] ?? [] ),
		];
		update_option( self::OPTION, $data, false );
	}

	public static function api_key( string $provider ): string {
		$settings = self::get();
		switch ( $provider ) {
			case 'unsplash':
				return AIKeyVault::decrypt( (string) ( $settings['unsplash_access_key'] ?? '' ) );
			case 'pexels':
				return AIKeyVault::decrypt( (string) ( $settings['pexels_api_key'] ?? '' ) );
			case 'pixabay':
				return AIKeyVault::decrypt( (string) ( $settings['pixabay_api_key'] ?? '' ) );
		}

		return '';
	}

	private static function sanitize_secret( string $raw, string $current ): string {
		$raw = trim( $raw );
		return '' !== $raw ? AIKeyVault::encrypt( $raw ) : $current;
	}

	private static function sanitize_enum( string $value, array $allowed, string $default ): string {
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_priority( $priority ): array {
		$allowed = [ 'ai', 'unsplash', 'pexels', 'pixabay', 'placeholder' ];
		if ( is_string( $priority ) ) {
			$priority = preg_split( '/[\r\n,>]+/', $priority ) ?: [];
		}
		if ( ! is_array( $priority ) ) {
			return self::defaults()['source_priority'];
		}
		$clean = [];
		foreach ( $priority as $item ) {
			$item = sanitize_key( (string) $item );
			if ( in_array( $item, $allowed, true ) && ! in_array( $item, $clean, true ) ) {
				$clean[] = $item;
			}
		}
		foreach ( $allowed as $item ) {
			if ( ! in_array( $item, $clean, true ) ) {
				$clean[] = $item;
			}
		}
		return $clean;
	}
}
