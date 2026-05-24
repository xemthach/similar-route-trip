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

		$min_words = (int) ( $length_profile['min'] ?? 0 );
		$min_tolerant = $min_words > 0 ? (int) floor( $min_words * 0.9 ) : 0;
		if ( $words < $min_tolerant ) {
			$errors[] = 'Word count thấp hơn ngưỡng tối thiểu.';
		}
		if ( empty( $structured['h1'] ) && ! preg_match( '/<h1\b/i', $content_html ) ) {
			$errors[] = 'Thiếu H1.';
		}
		if ( empty( $structured['meta_description'] ) ) {
			$errors[] = 'Thiếu meta description.';
		}
		$meta_len = mb_strlen( (string) ( $structured['meta_description'] ?? '' ) );
		if ( $meta_len > 0 && ( $meta_len < 120 || $meta_len > 170 ) ) {
			$errors[] = 'Meta description ngoài khoảng khuyến nghị 120-170 ký tự.';
		}
		if ( preg_match_all( '/<h2\b/i', $content_html ) < 3 ) {
			$errors[] = 'Thiếu chiều sâu section (ít hơn 3 H2).';
		}
		if ( '' !== $primary_keyword ) {
			$kw   = mb_strtolower( $primary_keyword );
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

		$errors = array_merge( $errors, self::validate_vietnamese_diacritics( $content_html, $text ) );
		$errors = array_merge( $errors, self::validate_repetition( $content_html ) );
		$errors = array_merge( $errors, self::validate_required_structure( $content_html ) );
		$errors = array_merge( $errors, self::validate_seo_safety( $content_html, $text ) );

		$score = max( 0, 100 - ( count( $errors ) * 12 ) );
		return [
			'passed' => empty( $errors ),
			'score'  => $score,
			'errors' => array_values( array_unique( $errors ) ),
			'words'  => $words,
		];
	}

	private static function validate_vietnamese_diacritics( string $content_html, string $text ): array {
		$errors = [];
		$normalized = mb_strtolower( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) ?? '' );
		$words = preg_split( '/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( empty( $words ) ) {
			return $errors;
		}

		$ascii_words = 0;
		foreach ( $words as $word ) {
			if ( preg_match( '/^[a-z0-9_-]{3,}$/i', $word ) ) {
				$ascii_words++;
			}
		}
		$ascii_ratio = $ascii_words / max( 1, count( $words ) );
		$marker_hits = 0;
		$markers = [ 'khong', 'duoc', 'gia', 'quang', 'duong', 'thoi', 'gian', 'hanh', 'trinh', 'khach' ];
		$lower_text = mb_strtolower( wp_strip_all_tags( $content_html ) );
		foreach ( $markers as $marker ) {
			$marker_hits += preg_match_all( '/\b' . preg_quote( $marker, '/' ) . '\b/u', $lower_text );
		}

		if ( $ascii_ratio > 0.38 && $marker_hits >= 5 ) {
			$errors[] = 'Nội dung có tỷ lệ tiếng Việt không dấu quá cao.';
		}
		return $errors;
	}

	private static function validate_repetition( string $content_html ): array {
		$errors = [];
		if ( ! preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $content_html, $matches ) ) {
			return $errors;
		}

		$paragraphs = [];
		foreach ( $matches[1] as $paragraph ) {
			$normalized = self::normalize_segment( (string) $paragraph );
			if ( '' !== $normalized ) {
				$paragraphs[] = $normalized;
			}
		}
		if ( count( $paragraphs ) < 2 ) {
			return $errors;
		}

		$counts = array_count_values( $paragraphs );
		foreach ( $counts as $count ) {
			if ( $count > 1 ) {
				$errors[] = 'Nội dung có đoạn bị lặp lại nhiều lần.';
				break;
			}
		}

		$total = count( $paragraphs );
		for ( $i = 0; $i < $total; $i++ ) {
			for ( $j = $i + 1; $j < $total; $j++ ) {
				if ( mb_strlen( $paragraphs[ $i ] ) < 90 || mb_strlen( $paragraphs[ $j ] ) < 90 ) {
					continue;
				}
				$score = self::jaccard_similarity( $paragraphs[ $i ], $paragraphs[ $j ] );
				if ( $score >= 0.9 ) {
					$errors[] = 'Nội dung có đoạn tương tự nhau quá mức.';
					break 2;
				}
			}
		}

		return $errors;
	}

	private static function validate_required_structure( string $content_html ): array {
		$errors = [];
		$lower_html = mb_strtolower( $content_html );
		if ( ! preg_match( '/(giá|gia|chi phí|chi phi|bảng giá|bang gia)/u', $lower_html ) ) {
			$errors[] = 'Thiếu section giá.';
		}
		if ( ! preg_match( '/(quãng đường|quang duong|thời gian|thoi gian|km|phút|phut)/u', $lower_html ) ) {
			$errors[] = 'Thiếu section quãng đường/thời gian.';
		}
		$has_faq = (bool) preg_match( '/<div[^>]+class="[^"]*srt-faq/i', $content_html )
			|| (bool) preg_match( '/<h2[^>]*>\s*(faq|câu hỏi|cau hoi)/iu', $content_html );
		if ( ! $has_faq ) {
			$errors[] = 'Thiếu FAQ.';
		}
		return $errors;
	}

	private static function validate_seo_safety( string $content_html, string $text ): array {
		$errors = [];
		$words  = self::count_words( $text );

		if ( $words > 220 ) {
			$normalized = mb_strtolower( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) ?? '' );
			$tokens = preg_split( '/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
			$unique = count( array_unique( $tokens ) );
			$ratio  = $unique / max( 1, count( $tokens ) );
			if ( $ratio < 0.22 ) {
				$errors[] = 'Nội dung có dấu hiệu mỏng/lặp ý (thin content).';
			}
		}

		$sentences = preg_split( '/[.!?]+\s+/u', wp_strip_all_tags( $content_html ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$prefix_counts = [];
		foreach ( $sentences as $sentence ) {
			$parts = preg_split( '/\s+/u', self::normalize_segment( $sentence ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
			if ( count( $parts ) < 4 ) {
				continue;
			}
			$prefix = implode( ' ', array_slice( $parts, 0, 4 ) );
			$prefix_counts[ $prefix ] = (int) ( $prefix_counts[ $prefix ] ?? 0 ) + 1;
		}
		foreach ( $prefix_counts as $count ) {
			if ( $count >= 5 ) {
				$errors[] = 'Nội dung có dấu hiệu lặp mở câu/filler.';
				break;
			}
		}

		return $errors;
	}

	private static function normalize_segment( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	private static function jaccard_similarity( string $a, string $b ): float {
		$a_tokens = array_values( array_unique( preg_split( '/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
		$b_tokens = array_values( array_unique( preg_split( '/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
		if ( empty( $a_tokens ) || empty( $b_tokens ) ) {
			return 0.0;
		}
		$intersect = count( array_intersect( $a_tokens, $b_tokens ) );
		$union     = count( array_unique( array_merge( $a_tokens, $b_tokens ) ) );
		return $union > 0 ? ( $intersect / $union ) : 0.0;
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
