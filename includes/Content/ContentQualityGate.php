<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

defined( 'ABSPATH' ) || exit;

final class ContentQualityGate {
	public static function evaluate( string $content_html, array $structured, array $length_profile, string $primary_keyword = '' ): array {
		$errors = [];
		$text   = trim( wp_strip_all_tags( $content_html ) );
		$words  = self::count_words( $text );
		$lower  = mb_strtolower( $text );

		if ( $words < (int) $length_profile['min'] ) {
			$errors[] = 'Word count thấp hơn ngưỡng tối thiểu.';
		}
		if ( empty( $structured['h1'] ) && ! preg_match( '/<h1\b/i', $content_html ) ) {
			$errors[] = 'Thiếu H1.';
		}
		if ( empty( $structured['meta_description'] ) ) {
			$errors[] = 'Thiếu meta description.';
		}
		$meta_len = strlen( (string) ( $structured['meta_description'] ?? '' ) );
		if ( $meta_len > 0 && ( $meta_len < 120 || $meta_len > 170 ) ) {
			$errors[] = 'Meta description ngoài khoảng khuyến nghị 120-170 ký tự.';
		}
		if ( preg_match_all( '/<h2\b/i', $content_html ) < 2 ) {
			$errors[] = 'Thiếu chiều sâu section (ít hơn 2 H2).';
		}
		if ( '' !== $primary_keyword ) {
			$kw = mb_strtolower( $primary_keyword );
			$hits = substr_count( $lower, $kw );
			if ( $hits > 0 && $words > 0 ) {
				$density = ( $hits / max( 1, $words ) ) * 100;
				if ( $density > 3.5 ) {
					$errors[] = 'Mật độ từ khóa chính quá cao.';
				}
			}
		}
		if ( false !== stripos( $content_html, '"seo_title"' ) || false !== stripos( $content_html, '"article_html"' ) ) {
			$errors[] = 'Content còn lộ JSON envelope.';
		}
		if ( preg_match( '/<div\s+class="faq[^>]*>$/i', trim( $content_html ) ) ) {
			$errors[] = 'FAQ markup bị cắt cụt.';
		}

		$score = max( 0, 100 - ( count( $errors ) * 15 ) );
		return [
			'passed' => empty( $errors ),
			'score'  => $score,
			'errors' => $errors,
			'words'  => $words,
		];
	}

	private static function count_words( string $text ): int {
		if ( '' === trim( $text ) ) {
			return 0;
		}
		if ( preg_match_all( '/\p{L}[\p{L}\p{M}\p{N}_-]*/u', $text, $m ) ) {
			return count( $m[0] );
		}
		return str_word_count( $text );
	}
}
