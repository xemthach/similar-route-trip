<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Content_Image_Inserter {
	public static function insert( string $content, array $images, array $settings = [] ): string {
		if ( '' === trim( $content ) || empty( $images ) ) {
			return $content;
		}

		$placement = (string) ( $settings['image_placement'] ?? 'after_intro' );
		switch ( $placement ) {
			case 'before_first_h2':
				return self::insert_before_first_h2( $content, $images );
			case 'after_every_n_headings':
				return self::insert_after_every_n_headings( $content, $images, max( 1, (int) ( $settings['image_heading_interval'] ?? 2 ) ) );
			case 'end_of_article':
				return $content . implode( '', array_map( [ self::class, 'figure_html' ], $images ) );
			case 'shortcode_placeholder':
				return self::replace_shortcode_placeholders( $content, $images );
			case 'after_intro':
			default:
				return self::insert_after_intro( $content, $images );
		}
	}

	private static function insert_after_intro( string $content, array $images ): string {
		$position = stripos( $content, '</p>' );
		if ( false === $position ) {
			return self::insert_before_first_h2( $content, $images );
		}
		$position += 4;
		return substr( $content, 0, $position ) . implode( '', array_map( [ self::class, 'figure_html' ], $images ) ) . substr( $content, $position );
	}

	private static function insert_before_first_h2( string $content, array $images ): string {
		if ( preg_match( '/<h2\b/i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$position = (int) $match[0][1];
			return substr( $content, 0, $position ) . implode( '', array_map( [ self::class, 'figure_html' ], $images ) ) . substr( $content, $position );
		}
		return $content . implode( '', array_map( [ self::class, 'figure_html' ], $images ) );
	}

	private static function insert_after_every_n_headings( string $content, array $images, int $interval ): string {
		if ( ! preg_match_all( '/<\/h2>/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content . implode( '', array_map( [ self::class, 'figure_html' ], $images ) );
		}
		$offsets = [];
		foreach ( $matches[0] as $index => $match ) {
			if ( 0 === ( ( $index + 1 ) % $interval ) ) {
				$offsets[] = (int) $match[1] + strlen( (string) $match[0] );
			}
		}
		if ( empty( $offsets ) ) {
			$offsets[] = (int) $matches[0][0][1] + strlen( (string) $matches[0][0][0] );
		}

		$image_index = 0;
		rsort( $offsets );
		foreach ( $offsets as $position ) {
			if ( ! isset( $images[ $image_index ] ) ) {
				break;
			}
			$content = substr( $content, 0, $position ) . self::figure_html( $images[ $image_index ] ) . substr( $content, $position );
			$image_index++;
		}
		if ( $image_index < count( $images ) ) {
			$content .= implode( '', array_map( [ self::class, 'figure_html' ], array_slice( $images, $image_index ) ) );
		}
		return $content;
	}

	private static function replace_shortcode_placeholders( string $content, array $images ): string {
		foreach ( $images as $image ) {
			if ( false === strpos( $content, '[srt_image_placeholder]' ) ) {
				$content .= self::figure_html( $image );
				continue;
			}
			$content = preg_replace( '/\[srt_image_placeholder\]/', self::figure_html( $image ), $content, 1 ) ?: $content;
		}
		return $content;
	}

	private static function figure_html( array $image ): string {
		$src       = esc_url( (string) ( $image['src'] ?? '' ) );
		$alt       = esc_attr( (string) ( $image['alt'] ?? '' ) );
		$caption   = trim( (string) ( $image['caption'] ?? '' ) );
		$caption_html = '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';

		return '<figure class="srt-generated-image"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" />' . $caption_html . '</figure>';
	}
}
