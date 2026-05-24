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

	public static function import_generated_image( array $candidate, int $post_id, array $args = [] ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = 0;
		$filename      = sanitize_file_name( (string) ( $args['filename'] ?? 'srt-generated-image.jpg' ) );
		if ( ! empty( $candidate['base64_data'] ) ) {
			$tmp = wp_tempnam( $filename );
			if ( ! $tmp ) {
				return [ 'success' => false, 'error' => 'Unable to create temp file for base64 image.' ];
			}
			$decoded = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', (string) $candidate['base64_data'] ), true );
			if ( false === $decoded || '' === $decoded ) {
				@unlink( $tmp );
				return [ 'success' => false, 'error' => 'Invalid base64 image payload.' ];
			}
			if ( false === file_put_contents( $tmp, $decoded ) ) {
				@unlink( $tmp );
				return [ 'success' => false, 'error' => 'Unable to write base64 image to temp file.' ];
			}
			$file = [
				'name'     => $filename,
				'tmp_name' => $tmp,
			];
			$attachment_id = media_handle_sideload( $file, $post_id, (string) ( $args['description'] ?? '' ) );
		} elseif ( ! empty( $candidate['local_file'] ) ) {
			$tmp = wp_tempnam( $filename );
			if ( ! $tmp || ! copy( (string) $candidate['local_file'], $tmp ) ) {
				return [ 'success' => false, 'error' => 'Unable to prepare local placeholder file.' ];
			}
			$file = [
				'name'     => $filename,
				'tmp_name' => $tmp,
			];
			$attachment_id = media_handle_sideload( $file, $post_id, (string) ( $args['description'] ?? '' ) );
		} else {
			$url = esc_url_raw( (string) ( $candidate['url'] ?? '' ) );
			if ( '' === $url ) {
				return [ 'success' => false, 'error' => 'Missing image URL.' ];
			}
			if ( 'unsplash' === (string) ( $candidate['source'] ?? '' ) && class_exists( SRT_Unsplash_Provider::class ) ) {
				SRT_Unsplash_Provider::trigger_download_tracking( $candidate );
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				return [ 'success' => false, 'error' => $tmp->get_error_message() ];
			}
			$file = [
				'name'     => $filename,
				'tmp_name' => $tmp,
			];
			$attachment_id = media_handle_sideload( $file, $post_id, (string) ( $args['description'] ?? '' ) );
		}

		if ( is_wp_error( $attachment_id ) ) {
			if ( ! empty( $tmp ) && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			return [ 'success' => false, 'error' => $attachment_id->get_error_message() ];
		}

		$attachment_id = (int) $attachment_id;
		if ( '' !== (string) ( $args['alt'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
		}
		wp_update_post(
			[
				'ID'           => $attachment_id,
				'post_title'   => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
				'post_excerpt' => sanitize_text_field( (string) ( $args['caption'] ?? '' ) ),
				'post_content' => sanitize_textarea_field( (string) ( $args['description'] ?? '' ) ),
			]
		);
		update_post_meta( $attachment_id, '_srt_generated_image', 1 );
		update_post_meta( $attachment_id, '_srt_image_source', sanitize_key( (string) ( $args['source'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_srt_image_credit', sanitize_text_field( (string) ( $args['credit'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_srt_image_source_url', esc_url_raw( (string) ( $candidate['html_url'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_srt_image_prompt', sanitize_textarea_field( (string) ( $args['prompt'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_srt_route_slug', sanitize_title( (string) ( $args['route_slug'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_srt_post_id', (int) ( $args['post_id'] ?? 0 ) );
		return [
			'success'       => true,
			'attachment_id' => $attachment_id,
			'caption'       => sanitize_text_field( (string) ( $args['caption'] ?? '' ) ),
		];
	}
}
