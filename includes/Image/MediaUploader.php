<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class MediaUploader {
	public static function sideload( string $url, int $post_id, string $alt = '' ): int {
		if ( '' === $url ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$file = [
			'name' => basename( parse_url( $url, PHP_URL_PATH ) ?: 'srt-route-image.jpg' ),
			'tmp_name' => $tmp,
		];
		$id = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return 0;
		}
		if ( '' !== $alt ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}
}
