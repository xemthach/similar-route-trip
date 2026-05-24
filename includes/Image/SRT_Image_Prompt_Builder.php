<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Image_Prompt_Builder {
	public static function build( array $route, array $post_context = [], array $settings = [] ): string {
		$style   = (string) ( $settings['image_style'] ?? 'realistic' );
		$title   = (string) ( $post_context['post_title'] ?? '' );
		$excerpt = trim( wp_strip_all_tags( (string) ( $post_context['post_excerpt'] ?? '' ) ) );
		$topic   = (string) ( $post_context['topic'] ?? 'route_landing' );
		$from    = (string) ( $route['from_city'] ?? '' );
		$to      = (string) ( $route['to_city'] ?? '' );
		$distance = (string) ( $route['distance_km'] ?? '' );
		$duration = (string) ( $route['duration_min'] ?? '' );
		$price   = (string) ( $route['price_display'] ?? '' );

		$base = self::style_sentence( $style );
		$topic_sentence = self::topic_sentence( $topic, $from, $to );
		$details = array_filter(
			[
				'' !== $title ? 'Post title context: ' . $title . '.' : '',
				'' !== $excerpt ? 'Article summary: ' . wp_trim_words( $excerpt, 28, '...' ) . '.' : '',
				'' !== $distance ? 'Route distance: ' . $distance . ' km.' : '',
				'' !== $duration ? 'Estimated duration: ' . $duration . ' minutes.' : '',
				'' !== $price ? 'Reference price: ' . $price . '.' : '',
			]
		);

		return trim(
			$base
			. ' '
			. $topic_sentence
			. ' '
			. implode( ' ', $details )
			. ' No text, no logo, no watermark, no close-up real faces, no clear license plates, no misleading brand identity.'
		);
	}

	public static function build_alt_text( array $route, array $post_context = [], string $mode = 'route-based', int $index = 0 ): string {
		$from  = (string) ( $route['from_city'] ?? '' );
		$to    = (string) ( $route['to_city'] ?? '' );
		$title = trim( (string) ( $post_context['post_title'] ?? '' ) );

		if ( 'title-based' === $mode && '' !== $title ) {
			return 0 === $index ? $title : $title . ' - image ' . ( $index + 1 );
		}

		if ( 'ai-generated' === $mode ) {
			$parts = [
				sprintf( 'Taxi %s di %s', $from, $to ),
				0 === $index ? 'khung canh tuyen duong' : 'hinh minh hoa bo sung',
			];
			return implode( ' - ', array_filter( $parts ) );
		}

		return 0 === $index
			? sprintf( 'Taxi %s di %s', $from, $to )
			: sprintf( 'Hinh minh hoa tuyen %s di %s so %d', $from, $to, $index + 1 );
	}

	public static function seo_filename( array $route, int $index = 0, string $ext = 'jpg' ): string {
		$slug = sanitize_title( sprintf( 'taxi-%s-di-%s', (string) ( $route['from_city'] ?? '' ), (string) ( $route['to_city'] ?? '' ) ) );
		if ( $index > 0 ) {
			$slug .= '-' . ( $index + 1 );
		}
		return $slug . '.' . ltrim( strtolower( $ext ), '.' );
	}

	private static function style_sentence( string $style ): string {
		switch ( $style ) {
			case 'local_travel':
				return 'Realistic travel photo with authentic Mekong Delta atmosphere, clean composition, natural daylight.';
			case 'taxi_service':
				return 'Realistic taxi service website banner, clean vehicle, bright daylight, trustworthy and professional composition.';
			case 'documentary':
				return 'Documentary-style realistic scene, authentic Vietnam road context, balanced light, natural details.';
			case 'clean_banner':
				return 'Clean website banner image, realistic photo style, wide composition, bright and uncluttered.';
			case 'realistic':
			default:
				return 'Realistic photo-style image, bright, clean, natural lighting, website-safe composition.';
		}
	}

	private static function topic_sentence( string $topic, string $from, string $to ): string {
		switch ( $topic ) {
			case 'travel_guide':
				return sprintf( 'Show travel scenery near %s, Vietnam, with local roads and a warm countryside feeling.', $to );
			case 'food_guide':
				return sprintf( 'Show authentic Vietnamese local food culture in %s, with natural light and clean table styling.', $to );
			case 'route_landing':
			default:
				return sprintf( 'Show a clean taxi car traveling from %s to %s in the Mekong Delta region of Vietnam.', $from, $to );
		}
	}
}
