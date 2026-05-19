<?php
/**
 * Shortcodes for popular routes.
 *
 * @package SimilarRouteTrip\Shortcodes
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\Shortcodes;

use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Routes\SimilarRouteFinder;

defined( 'ABSPATH' ) || exit;

final class ShortcodeController {

	private const FIELD_MAP = [
		'from'        => 'from_city',
		'to'          => 'to_city',
		'distance'    => 'distance_km',
		'duration'    => 'duration_min',
		'price'       => 'price_display',
		'price_min'   => 'price_min',
		'intro'       => 'intro',
		'booking_url' => 'booking_url',
		'url'         => 'landing_url',
	];

	public static function init(): void {
		add_shortcode( 'srt_route', [ self::class, 'route_field' ] );
		add_shortcode( 'srt_route_card', [ self::class, 'route_card' ] );
		add_shortcode( 'srt_route_table', [ self::class, 'route_table' ] );
		add_shortcode( 'srt_route_faq', [ self::class, 'route_faq' ] );
		add_shortcode( 'srt_similar_routes', [ self::class, 'similar_routes' ] );
	}

	public static function route_field( $atts ): string {
		$atts = shortcode_atts( [ 'slug' => '', 'field' => 'price' ], $atts, 'srt_route' );
		$route = RouteRepository::get( sanitize_title( $atts['slug'] ) );
		if ( ! $route ) {
			return '';
		}

		$field = sanitize_key( $atts['field'] );
		if ( ! isset( self::FIELD_MAP[ $field ] ) ) {
			return '';
		}

		return esc_html( (string) ( $route[ self::FIELD_MAP[ $field ] ] ?? '' ) );
	}

	public static function route_card( $atts ): string {
		$atts  = shortcode_atts( [ 'slug' => '' ], $atts, 'srt_route_card' );
		$route = RouteRepository::get( sanitize_title( $atts['slug'] ) );
		if ( ! $route ) {
			return '';
		}

		return self::render_card( $route );
	}

	public static function route_table( $atts ): string {
		$atts   = shortcode_atts( [ 'from' => '', 'limit' => 50 ], $atts, 'srt_route_table' );
		$routes = RouteRepository::all( [ 'active' => true, 'limit' => (int) $atts['limit'] ] );
		$from   = sanitize_title( $atts['from'] );

		if ( '' !== $from ) {
			$routes = array_values(
				array_filter(
					$routes,
					static fn( array $route ): bool => sanitize_title( (string) $route['from_slug'] ) === $from
				)
			);
		}

		if ( empty( $routes ) ) {
			return '';
		}

		ob_start();
		?>
		<table class="srt-route-table">
			<thead><tr><th>Tuyen</th><th>Km</th><th>Thoi gian</th><th>Gia tu</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $routes as $route ) : ?>
				<tr>
					<td><?php echo esc_html( $route['from_city'] . ' - ' . $route['to_city'] ); ?></td>
					<td><?php echo esc_html( number_format( (float) $route['distance_km'], 1 ) ); ?> km</td>
					<td><?php echo esc_html( (string) $route['duration_min'] ); ?> phut</td>
					<td><?php echo esc_html( (string) $route['price_display'] ); ?></td>
					<td><a href="<?php echo esc_url( (string) $route['booking_url'] ); ?>">Dat xe</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	public static function route_faq( $atts ): string {
		$atts  = shortcode_atts( [ 'slug' => '' ], $atts, 'srt_route_faq' );
		$route = RouteRepository::get( sanitize_title( $atts['slug'] ) );
		if ( ! $route ) {
			return '';
		}
		$faqs = json_decode( (string) $route['faqs_json'], true );
		if ( ! is_array( $faqs ) || empty( $faqs ) ) {
			return '';
		}

		ob_start();
		echo '<div class="srt-route-faq">';
		foreach ( $faqs as $faq ) {
			if ( empty( $faq['question'] ) ) {
				continue;
			}
			printf(
				'<details><summary>%s</summary><p>%s</p></details>',
				esc_html( (string) $faq['question'] ),
				esc_html( (string) ( $faq['answer'] ?? '' ) )
			);
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	public static function similar_routes( $atts ): string {
		$atts   = shortcode_atts( [ 'slug' => '', 'limit' => 6 ], $atts, 'srt_similar_routes' );
		$routes = SimilarRouteFinder::find( sanitize_title( $atts['slug'] ), (int) $atts['limit'] );
		if ( empty( $routes ) ) {
			return '';
		}

		return '<div class="srt-similar-routes">' . implode( '', array_map( [ self::class, 'render_card' ], $routes ) ) . '</div>';
	}

	private static function render_card( array $route ): string {
		$title = sprintf( '%s di %s', $route['from_city'] ?? '', $route['to_city'] ?? '' );
		ob_start();
		?>
		<div class="srt-route-card">
			<strong><?php echo esc_html( $title ); ?></strong>
			<div><?php echo esc_html( number_format( (float) $route['distance_km'], 1 ) ); ?> km - <?php echo esc_html( (string) $route['duration_min'] ); ?> phut</div>
			<div><?php echo esc_html( (string) $route['price_display'] ); ?></div>
			<a href="<?php echo esc_url( (string) $route['booking_url'] ); ?>">Dat xe</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
