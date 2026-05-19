<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

defined( 'ABSPATH' ) || exit;

final class ContentSimilarityChecker {
	public static function compare( string $candidate_html, array $other_html_list ): array {
		$candidate = self::normalize_text( $candidate_html );
		if ( '' === $candidate ) {
			return [ 'score' => 0.0, 'matched_post_id' => 0, 'matched_text' => '', 'reason' => 'empty_candidate' ];
		}

		$candidate_ngrams = self::ngrams( $candidate, 3 );
		$candidate_headings = self::headings_signature( $candidate_html );
		$candidate_intro    = self::intro_signature( $candidate_html );
		$best_score = 0.0;
		$best_id    = 0;
		$best_text  = '';
		$best_breakdown = [
			'text'    => 0.0,
			'heading' => 0.0,
			'intro'   => 0.0,
		];

		foreach ( $other_html_list as $item ) {
			$post_id = (int) ( $item['post_id'] ?? 0 );
			$text    = self::normalize_text( (string) ( $item['content'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$text_score    = self::jaccard( $candidate_ngrams, self::ngrams( $text, 3 ) );
			$heading_score = self::jaccard( $candidate_headings, self::headings_signature( (string) ( $item['content'] ?? '' ) ) );
			$intro_score   = self::jaccard( $candidate_intro, self::intro_signature( (string) ( $item['content'] ?? '' ) ) );
			$score         = ( $text_score * 0.6 ) + ( $heading_score * 0.25 ) + ( $intro_score * 0.15 );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_id    = $post_id;
				$best_text  = $text;
				$best_breakdown = [
					'text'    => round( $text_score, 4 ),
					'heading' => round( $heading_score, 4 ),
					'intro'   => round( $intro_score, 4 ),
				];
			}
		}

		return [
			'score'           => round( $best_score, 4 ),
			'matched_post_id' => $best_id,
			'matched_text'    => $best_text,
			'breakdown'       => $best_breakdown,
			'reason'          => $best_score >= 0.8 ? 'high_similarity' : ( $best_score >= 0.6 ? 'moderate_similarity' : 'ok' ),
		];
	}

	public static function is_too_similar( string $candidate_html, array $other_html_list, float $threshold = 0.78 ): array {
		$result = self::compare( $candidate_html, $other_html_list );
		$result['too_similar'] = $result['score'] >= $threshold;
		$result['threshold'] = $threshold;
		return $result;
	}

	private static function normalize_text( string $html ): string {
		$text = trim( wp_strip_all_tags( $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	private static function ngrams( string $text, int $size = 3 ): array {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( count( $tokens ) < $size ) {
			return $tokens ? [ implode( ' ', $tokens ) ] : [];
		}
		$grams = [];
		$limit = count( $tokens ) - $size + 1;
		for ( $i = 0; $i < $limit; $i++ ) {
			$grams[] = implode( ' ', array_slice( $tokens, $i, $size ) );
		}
		return array_values( array_unique( $grams ) );
	}

	private static function headings_signature( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}
		$items = [];
		if ( preg_match_all( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$heading = self::normalize_text( (string) $heading );
				if ( '' !== $heading ) {
					$items[] = $heading;
				}
			}
		}
		return array_values( array_unique( $items ) );
	}

	private static function intro_signature( string $html ): array {
		$text = self::normalize_text( $html );
		if ( '' === $text ) {
			return [];
		}
		$sentences = preg_split( '/[.!?]+\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$intro     = implode( ' ', array_slice( $sentences, 0, 2 ) );
		$intro     = trim( preg_replace( '/\s+/u', ' ', $intro ) ?? $intro );
		if ( '' === $intro ) {
			return [];
		}
		$tokens = preg_split( '/\s+/u', $intro, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		return self::ngrams( implode( ' ', array_slice( $tokens, 0, 24 ) ), 2 );
	}

	private static function jaccard( array $a, array $b ): float {
		$a = array_values( array_unique( $a ) );
		$b = array_values( array_unique( $b ) );
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$intersect = count( array_intersect( $a, $b ) );
		$union     = count( array_unique( array_merge( $a, $b ) ) );
		return $union > 0 ? $intersect / $union : 0.0;
	}
}
