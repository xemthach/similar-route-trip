<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

use SimilarRouteTrip\AI\AIConfig;
use SimilarRouteTrip\AI\AIConfigResolver;
use SimilarRouteTrip\AI\AIService;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Logging\LogRepository;
use SimilarRouteTrip\Content\ContentSimilarityChecker;
use SimilarRouteTrip\Queue\QueueRepository;

defined( 'ABSPATH' ) || exit;

final class ContentGenerator {
	public static function preview( array $route, string $template_key = 'route_landing', bool $use_ai = false, array $context = [] ): array {
		$templates = PromptTemplateManager::get();
		$template  = (string) ( $templates[ $template_key ] ?? $templates['route_landing'] );
		$prompt    = PlaceholderResolver::resolve( $template, $route, $context );
		$prompt   .= self::build_topic_guidance( $route, $context );
		$length_profile = ContentLengthProfile::resolve(
			(string) ( $context['content_length'] ?? 'standard' ),
			(int) ( $context['min_words'] ?? 0 ),
			(int) ( $context['max_words'] ?? 0 )
		);

		if ( ! $use_ai ) {
			$structured = self::build_fallback_structured_payload( $route, '', $template_key, $length_profile, $context );
			$content    = (string) ( $structured['article_html'] ?? self::fallback_content( $route ) );
			$quality    = ContentQualityGate::evaluate( self::clean_generated_html( $content ), $structured, $length_profile, (string) ( $context['primary_keyword'] ?? '' ) );
			$similarity = [
				'score'           => 0.0,
				'matched_post_id' => 0,
				'too_similar'     => false,
				'breakdown'       => [
					'text'    => 0,
					'heading' => 0,
					'intro'   => 0,
				],
			];
			if ( ! empty( $route['id'] ) ) {
				$similarity = self::similarity_check( (int) $route['id'], (string) ( $context['topic_id'] ?? $template_key ), (string) $content );
			}
			return [
				'success'            => true,
				'prompt'             => $prompt,
				'content'            => $content,
				'structured'         => $structured,
				'preview_quality'    => $quality,
				'preview_similarity' => $similarity,
				'preview_warnings'   => array_values(
					array_unique(
						array_merge(
							(array) ( $quality['errors'] ?? [] ),
							! empty( $similarity['too_similar'] ) ? [ sprintf( 'Content similarity cao voi post #%d.', (int) ( $similarity['matched_post_id'] ?? 0 ) ) ] : []
						)
					)
				),
			];
		}

		$config = AIConfig::get();
		if ( empty( $config['enable_content'] ) ) {
			return [ 'success' => false, 'prompt' => $prompt, 'content' => '', 'error' => 'AI content generation disabled.' ];
		}

		$result = AIService::provider()->generate_text( $prompt );
		if ( ! empty( $result['success'] ) ) {
			$plain_len = strlen( trim( wp_strip_all_tags( (string) ( $result['content'] ?? '' ) ) ) );
			if ( 'route_landing' === $template_key && $plain_len < 1200 ) {
				$retry_prompt = $prompt . "\n\nYeu cau: viet lai day du hon, co H2/H3, gia, quang duong, thoi gian, FAQ va CTA mem.";
				$retry        = AIService::provider()->generate_text( $retry_prompt );
				if ( ! empty( $retry['success'] ) ) {
					$retry_len = strlen( trim( wp_strip_all_tags( (string) ( $retry['content'] ?? '' ) ) ) );
					if ( $retry_len > $plain_len ) {
						$result = $retry;
					}
				}
			}
		}

		$structured = [];
		if ( ! empty( $result['success'] ) ) {
			$structured = self::parse_structured_payload( (string) ( $result['content'] ?? '' ) );
			$result['content'] = self::strip_json_envelope_from_content( (string) ( $result['content'] ?? '' ), $structured );
			if ( empty( $structured['article_html'] ) ) {
				$coerce = AIService::provider()->generate_text( self::build_json_enforcement_prompt( $route, (string) ( $result['content'] ?? '' ) ) );
				if ( ! empty( $coerce['success'] ) ) {
					$coerced = self::parse_structured_payload( (string) ( $coerce['content'] ?? '' ) );
					if ( ! empty( $coerced['article_html'] ) ) {
						$structured = $coerced;
						$result['content'] = self::strip_json_envelope_from_content( (string) ( $coerce['content'] ?? '' ), $structured );
					}
				}
			}
			if ( empty( $structured['article_html'] ) ) {
				$structured = self::build_fallback_structured_payload( $route, (string) ( $result['content'] ?? '' ), $template_key, $length_profile, $context );
			}
			if ( ! empty( $structured['article_html'] ) ) {
				$result['content'] = self::clean_generated_html( (string) $structured['article_html'] );
			}
			$plain_len = strlen( trim( wp_strip_all_tags( (string) ( $result['content'] ?? '' ) ) ) );
			if ( $plain_len < 700 || empty( $structured['article_html'] ) ) {
				$structured = self::build_fallback_structured_payload( $route, (string) ( $result['content'] ?? '' ), $template_key, $length_profile, $context );
				$result['content'] = self::clean_generated_html( (string) ( $structured['article_html'] ?? '' ) );
			}
			$result['content'] = self::maybe_restore_vietnamese_diacritics( (string) $result['content'], $route, $context );
			$result['content'] = self::normalize_common_vietnamese_phrases( (string) $result['content'] );
			if ( ! empty( $structured['article_html'] ) ) {
				$structured['article_html'] = self::maybe_restore_vietnamese_diacritics( (string) $structured['article_html'], $route, $context );
				$structured['article_html'] = self::normalize_common_vietnamese_phrases( (string) $structured['article_html'] );
			}
		}

		if ( empty( $structured['seo_title'] ) ) {
			$structured['seo_title'] = self::route_title( $route );
		}
		if ( empty( $structured['meta_description'] ) ) {
			$structured['meta_description'] = self::build_meta_description( $route, (string) ( $context['topic_id'] ?? $template_key ) );
		}
		if ( empty( $structured['h1'] ) ) {
			$structured['h1'] = self::route_title( $route );
		}
		if ( empty( $structured['slug_suggestion'] ) ) {
			$structured['slug_suggestion'] = (string) ( $route['slug'] ?? '' );
		}
		if ( empty( $structured['article_html'] ) ) {
			$structured['article_html'] = self::clean_generated_html( (string) ( $result['content'] ?? self::fallback_content( $route ) ) );
		}
		if ( empty( $structured['faq'] ) ) {
			$structured['faq'] = self::build_manual_faq( $route, (string) ( $context['topic_id'] ?? $template_key ), $context );
		}
		if ( empty( $structured['featured_image_prompt'] ) ) {
			$structured['featured_image_prompt'] = sprintf( 'Anh taxi thuc te cho tuyen %s di %s, phong cach mien Tay Viet Nam, sang ro, chuyen nghiep.', (string) ( $route['from_city'] ?? '' ), (string) ( $route['to_city'] ?? '' ) );
		}
		if ( empty( $structured['schema_summary'] ) ) {
			$structured['schema_summary'] = 'Service + FAQPage';
		}

		$preview_quality = ContentQualityGate::evaluate(
			self::clean_generated_html( (string) ( $structured['article_html'] ?? (string) ( $result['content'] ?? '' ) ) ),
			$structured,
			$length_profile,
			(string) ( $context['primary_keyword'] ?? '' )
		);
		$preview_similarity = [
			'score'           => 0.0,
			'matched_post_id' => 0,
			'too_similar'     => false,
			'breakdown'       => [
				'text'    => 0,
				'heading' => 0,
				'intro'   => 0,
			],
		];
		if ( ! empty( $route['id'] ) ) {
			$preview_similarity = self::similarity_check( (int) $route['id'], (string) ( $context['topic_id'] ?? $template_key ), (string) ( $structured['article_html'] ?? (string) ( $result['content'] ?? '' ) ) );
		}
		$result['preview_quality'] = $preview_quality;
		$result['preview_similarity'] = $preview_similarity;
		$result['preview_warnings'] = array_values(
			array_unique(
				array_merge(
					(array) ( $preview_quality['errors'] ?? [] ),
					! empty( $preview_similarity['too_similar'] ) ? [ sprintf( 'Content similarity cao voi post #%d.', (int) ( $preview_similarity['matched_post_id'] ?? 0 ) ) ] : []
				)
			)
		);

		LogRepository::add(
			empty( $result['success'] ) ? 'error' : 'info',
			'ai_content_request',
			$result['error'] ?? 'AI content generated.',
			[
				'route_id' => (int) ( $route['id'] ?? 0 ),
				'provider' => $config['provider'] ?? '',
				'source'   => AIConfigResolver::get_source(),
			]
		);

		return array_merge( [ 'prompt' => $prompt, 'structured' => $structured ], $result );
	}

	public static function fallback_content( array $route ): string {
		return self::build_manual_article_html( $route, 'route_landing' );
	}

	public static function sanitize_generated_content( string $content ): string {
		return self::clean_generated_html( $content );
	}

	public static function create_post( int $route_id, array $args = [] ): array {
		$route = RouteRepository::get_by_id( $route_id );
		if ( ! $route ) {
			return [ 'success' => false, 'error' => 'Route not found.' ];
		}

		$existing = get_posts(
			[
				'post_type'      => 'any',
				'meta_key'       => '_srt_route_slug',
				'meta_value'     => $route['slug'],
				'numberposts'    => 1,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'fields'         => 'ids',
			]
		);
		$existing_id = ! empty( $existing ) ? (int) $existing[0] : 0;
		if ( $existing_id > 0 && empty( $args['regenerate'] ) ) {
			return [
				'success'  => false,
				'error'    => 'Post already exists.',
				'post_id'  => $existing_id,
				'edit_url' => self::edit_url( $existing_id ),
				'view_url' => get_permalink( $existing_id ),
			];
		}

		$topic = TopicTemplateRegistry::sanitize_topic( (string) ( $args['topic'] ?? 'route_landing' ) );
		$topic_meta = TopicTemplateRegistry::get( $topic );
		$length_profile = ContentLengthProfile::resolve(
			(string) ( $args['content_length'] ?? ( $topic_meta['length_default'] ?? 'standard' ) ),
			(int) ( $args['min_words'] ?? 0 ),
			(int) ( $args['max_words'] ?? 0 )
		);

		$context = [
			'topic_id'            => $topic,
			'topic_label'         => (string) ( $topic_meta['label'] ?? 'Route Landing' ),
			'content_length'      => $length_profile['id'],
			'min_words'           => (int) $length_profile['min'],
			'max_words'           => (int) $length_profile['max'],
			'primary_keyword'     => sanitize_text_field( (string) ( $args['primary_keyword'] ?? '' ) ),
			'secondary_keywords'  => sanitize_text_field( (string) ( $args['secondary_keywords'] ?? '' ) ),
			'search_intent'       => (string) ( $topic_meta['intent'] ?? '' ),
		];

		$preview = self::preview( $route, (string) ( $args['template'] ?? $topic ), ! empty( $args['use_ai'] ), $context );
		if ( empty( $preview['success'] ) && empty( $preview['content'] ) ) {
			LogRepository::add(
				'warning',
				'ai_preview_failed',
				'AI preview failed; fallback content will be used.',
				[ 'route_id' => $route_id, 'topic' => $topic ]
			);
			$manual_structured = self::build_fallback_structured_payload( $route, '', $topic, $length_profile, $context );
			$preview = [
				'success'   => true,
				'content'   => (string) ( $manual_structured['article_html'] ?? self::fallback_content( $route ) ),
				'structured'=> $manual_structured,
			];
		}

		$content = self::clean_generated_html( (string) ( $preview['content'] ?? '' ) );
		$content = self::maybe_restore_vietnamese_diacritics( $content, $route, $context );
		$content = self::normalize_common_vietnamese_phrases( $content );
		if ( strlen( trim( wp_strip_all_tags( $content ) ) ) < 80 ) {
			return [ 'success' => false, 'error' => 'Generated content is empty or too short.' ];
		}

		$structured = (array) ( $preview['structured'] ?? [] );
		$content    = self::remove_broken_faq_markup( $content );
		$content    = self::append_structured_faq_html( $content, (array) ( $structured['faq'] ?? [] ) );
		$content    = self::maybe_restore_vietnamese_diacritics( $content, $route, $context );
		$content    = self::normalize_common_vietnamese_phrases( $content );
		$quality    = ContentQualityGate::evaluate( $content, $structured, $length_profile, (string) $context['primary_keyword'] );
		if ( empty( $quality['passed'] ) ) {
			LogRepository::add( 'warning', 'content_quality_failed', implode( ' | ', (array) $quality['errors'] ), [ 'route_id' => $route_id ] );
			$manual_structured = self::build_fallback_structured_payload( $route, (string) ( $preview['content'] ?? '' ), $topic, $length_profile, $context );
			$manual_content    = self::clean_generated_html( (string) ( $manual_structured['article_html'] ?? '' ) );
			$manual_content    = self::remove_broken_faq_markup( $manual_content );
			$manual_content    = self::append_structured_faq_html( $manual_content, (array) ( $manual_structured['faq'] ?? [] ) );
			$manual_content    = self::maybe_restore_vietnamese_diacritics( $manual_content, $route, $context );
			$manual_content    = self::normalize_common_vietnamese_phrases( $manual_content );
			$manual_quality    = ContentQualityGate::evaluate( $manual_content, $manual_structured, $length_profile, (string) $context['primary_keyword'] );
			if ( ! empty( $manual_quality['passed'] ) ) {
				LogRepository::add( 'info', 'content_quality_fallback', 'AI output failed quality gate; manual fallback applied.', [ 'route_id' => $route_id ] );
				$structured = $manual_structured;
				$content    = $manual_content;
				$quality    = $manual_quality;
			} else {
				return [ 'success' => false, 'error' => 'Quality gate failed: ' . implode( ' ', (array) $quality['errors'] ) ];
			}
		}

		$similarity = self::similarity_check( $route_id, $topic, $content );
		if ( ! empty( $similarity['too_similar'] ) ) {
			LogRepository::add(
				'warning',
				'content_similarity_blocked',
				'Generated content is too similar to an existing article.',
				[
					'route_id'         => $route_id,
					'matched_post_id'  => (int) ( $similarity['matched_post_id'] ?? 0 ),
					'similarity_score' => (float) ( $similarity['score'] ?? 0 ),
				]
			);
			return [
				'success' => false,
				'error'   => 'Generated content is too similar to existing post #' . (int) ( $similarity['matched_post_id'] ?? 0 ) . '.',
			];
		}

		$title = self::route_title( $route );
		if ( ! empty( $structured['h1'] ) ) {
			$title = sanitize_text_field( (string) $structured['h1'] );
		} elseif ( ! empty( $structured['seo_title'] ) ) {
			$title = sanitize_text_field( (string) $structured['seo_title'] );
		}
		$post_name = ! empty( $structured['slug_suggestion'] ) ? sanitize_title( (string) $structured['slug_suggestion'] ) : '';
		$content_hash = hash( 'sha256', $content );
		$duplicate_id = self::find_duplicate_content_hash( $content_hash, $existing_id );
		if ( $duplicate_id > 0 ) {
			return [ 'success' => false, 'error' => 'Content is too similar to existing generated post #' . $duplicate_id . '.' ];
		}

		$postarr = [
			'post_type'    => in_array( (string) ( $args['post_type'] ?? 'post' ), get_post_types(), true ) ? (string) $args['post_type'] : 'post',
			'post_status'  => in_array( (string) ( $args['status'] ?? 'draft' ), [ 'draft', 'pending', 'publish' ], true ) ? (string) $args['status'] : 'draft',
			'post_title'   => $title,
			'post_name'    => $post_name,
			'post_content' => wp_kses_post( $content ),
			'post_author'  => get_current_user_id() ?: 1,
			'meta_input'   => [
				'_srt_route_id'           => $route_id,
				'_srt_route_slug'         => $route['slug'],
				'_srt_generated_by_ai'    => ! empty( $args['use_ai'] ) ? 1 : 0,
				'_srt_ai_provider'        => AIConfig::get()['provider'] ?? '',
				'_srt_ai_config_source'   => AIConfigResolver::get_source(),
				'_srt_content_version'    => SRT_VERSION,
				'_srt_topic'              => $topic,
				'_srt_content_length'     => (string) $length_profile['id'],
				'_srt_quality_score'      => (int) $quality['score'],
				'_srt_similarity_score'    => (float) ( $similarity['score'] ?? 0 ),
				'_srt_similarity_post_id'  => (int) ( $similarity['matched_post_id'] ?? 0 ),
				'_srt_seo_title'          => sanitize_text_field( (string) ( $structured['seo_title'] ?? '' ) ),
				'_srt_meta_description'   => sanitize_textarea_field( (string) ( $structured['meta_description'] ?? '' ) ),
				'_srt_featured_image_prompt' => sanitize_textarea_field( (string) ( $structured['featured_image_prompt'] ?? '' ) ),
				'_srt_schema_summary'     => sanitize_textarea_field( (string) ( $structured['schema_summary'] ?? '' ) ),
				'_srt_content_hash'       => $content_hash,
				'_srt_similarity_breakdown' => wp_json_encode( (array) ( $similarity['breakdown'] ?? [] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			],
		];

		$post_id = ( $existing_id > 0 && ! empty( $args['regenerate'] ) )
			? wp_update_post( array_merge( $postarr, [ 'ID' => $existing_id ] ), true )
			: wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return [ 'success' => false, 'error' => $post_id->get_error_message() ];
		}

		self::maybe_update_seo_meta(
			(int) $post_id,
			! empty( $structured['seo_title'] ) ? (string) $structured['seo_title'] : $title,
			! empty( $structured['meta_description'] ) ? (string) $structured['meta_description'] : $content
		);
		self::auto_assign_seo_tags( (int) $post_id, $route, $topic, (string) $context['primary_keyword'], (string) $context['secondary_keywords'] );
		RouteRepository::update_generation_meta(
			$route_id,
			[
				'post_id'           => (int) $post_id,
				'post_status'       => get_post_status( (int) $post_id ),
				'generated_at'      => current_time( 'mysql' ),
				'last_generated_at' => current_time( 'mysql' ),
				'ai_config_source'  => AIConfigResolver::get_source(),
				'content_hash'      => $content_hash,
				'ai_status'         => 'completed',
			]
		);

		$ai_settings = AIConfig::get();
		$queue_image = ! empty( $ai_settings['enable_image'] ) && ! empty( $ai_settings['enable_featured_image'] );
		if ( $queue_image ) {
			$queue_id = QueueRepository::add(
				$route_id,
				'generate_image',
				[
					'post_id'   => (int) $post_id,
					'overwrite' => false,
				]
			);
			if ( $queue_id > 0 ) {
				RouteRepository::update_generation_meta(
					$route_id,
					[
						'ai_status' => 'image_queued',
					]
				);
				LogRepository::add( 'info', 'image_queue_created', 'Featured image task queued after post creation.', [ 'route_id' => $route_id, 'post_id' => (int) $post_id, 'queue_id' => $queue_id ] );
			}
		}

		LogRepository::add( 'info', 'post_created', 'Route post created/updated.', [ 'route_id' => $route_id, 'post_id' => (int) $post_id ] );

		return [
			'success'     => true,
			'post_id'     => (int) $post_id,
			'edit_url'    => self::edit_url( (int) $post_id ),
			'view_url'    => get_permalink( (int) $post_id ),
			'quality_score'  => (int) $quality['score'],
			'similarity'     => (array) $similarity,
		];
	}

	private static function route_title( array $route ): string {
		$from = (string) ( $route['from_city'] ?? '' );
		$to   = (string) ( $route['to_city'] ?? '' );
		return sprintf( 'Taxi %s di %s', $from, $to );
	}

	private static function similarity_check( int $route_id, string $topic, string $content ): array {
		$others = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => 12,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'   => '_srt_generated_by_ai',
						'value' => 1,
					],
				],
			]
		);
		$list = [];
		foreach ( $others as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			$route_meta_id = (int) get_post_meta( $post_id, '_srt_route_id', true );
			if ( $route_meta_id === $route_id ) {
				continue;
			}
			$post_topic = (string) get_post_meta( $post_id, '_srt_topic', true );
			if ( '' !== $topic && '' !== $post_topic && $post_topic !== $topic ) {
				continue;
			}
			$list[] = [
				'post_id' => $post_id,
				'content' => (string) get_post_field( 'post_content', $post_id ),
			];
		}
		if ( empty( $list ) ) {
			return [ 'score' => 0.0, 'matched_post_id' => 0, 'too_similar' => false ];
		}
		return ContentSimilarityChecker::is_too_similar( $content, $list, 0.78 );
	}

	private static function parse_structured_payload( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [];
		}

		if ( preg_match( '/^```(?:json|html)?\s*([\s\S]*?)\s*```$/s', $raw, $m ) ) {
			$raw = trim( (string) $m[1] );
		}
		$raw = preg_replace( '/^\s*[^\{\[]*?(\{[\s\S]*\}|\[[\s\S]*\])\s*$/', '$1', $raw ) ?? $raw;

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return self::normalize_structured_payload( $decoded );
		}

		if ( preg_match( '/\{[\s\S]*\}/', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return self::normalize_structured_payload( $decoded );
			}
		}

		if ( preg_match( '/\[[\s\S]*\]/', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return self::normalize_structured_payload( [ 'faq' => $decoded ] );
			}
		}

		return [];
	}

	private static function normalize_structured_payload( array $decoded ): array {
		if ( ! empty( $decoded['article'] ) && is_array( $decoded['article'] ) ) {
			$article = $decoded['article'];
			if ( empty( $decoded['article_html'] ) ) {
				$decoded['article_html'] = (string) ( $article['article_html'] ?? $article['content_html'] ?? $article['content'] ?? $article['body'] ?? '' );
			}
			if ( empty( $decoded['seo_title'] ) ) {
				$decoded['seo_title'] = (string) ( $article['seo_title'] ?? $article['title'] ?? '' );
			}
			if ( empty( $decoded['meta_description'] ) ) {
				$decoded['meta_description'] = (string) ( $article['meta_description'] ?? $article['description'] ?? '' );
			}
			if ( empty( $decoded['h1'] ) ) {
				$decoded['h1'] = (string) ( $article['h1'] ?? $article['title'] ?? '' );
			}
			if ( empty( $decoded['slug_suggestion'] ) ) {
				$decoded['slug_suggestion'] = (string) ( $article['slug_suggestion'] ?? $article['slug'] ?? '' );
			}
			if ( empty( $decoded['faq'] ) && ! empty( $article['faq'] ) && is_array( $article['faq'] ) ) {
				$decoded['faq'] = $article['faq'];
			}
			if ( empty( $decoded['featured_image_prompt'] ) ) {
				$decoded['featured_image_prompt'] = (string) ( $article['featured_image_prompt'] ?? $article['image_prompt'] ?? '' );
			}
			if ( empty( $decoded['internal_links'] ) && ! empty( $article['internal_links'] ) && is_array( $article['internal_links'] ) ) {
				$decoded['internal_links'] = $article['internal_links'];
			}
			if ( empty( $decoded['schema_summary'] ) ) {
				$decoded['schema_summary'] = (string) ( $article['schema_summary'] ?? $article['schema'] ?? '' );
			}
		}

		if ( empty( $decoded['article_html'] ) && ! empty( $decoded['content_html'] ) ) {
			$decoded['article_html'] = (string) $decoded['content_html'];
		}
		if ( empty( $decoded['article_html'] ) && ! empty( $decoded['content'] ) && is_string( $decoded['content'] ) ) {
			$decoded['article_html'] = (string) $decoded['content'];
		}

		return $decoded;
	}

	private static function build_json_enforcement_prompt( array $route, string $article_html ): string {
		$from = (string) ( $route['from_city'] ?? '' );
		$to   = (string) ( $route['to_city'] ?? '' );
		$slug = (string) ( $route['slug'] ?? '' );

		return "Chuyen doi noi dung route taxi sau thanh mot JSON object dung schema.\n"
			. "Khong viet them giai thich. Chi tra ve JSON hop le, khong markdown, khong code fence.\n"
			. "Schema:\n"
			. "{\n"
			. "  \"seo_title\": \"...\",\n"
			. "  \"meta_description\": \"...\",\n"
			. "  \"h1\": \"...\",\n"
			. "  \"slug_suggestion\": \"...\",\n"
			. "  \"article_html\": \"...\",\n"
			. "  \"faq\": [{\"question\": \"...\", \"answer\": \"...\"}],\n"
			. "  \"featured_image_prompt\": \"...\",\n"
			. "  \"internal_links\": [{\"anchor\": \"...\", \"target\": \"...\", \"reason\": \"...\"}],\n"
			. "  \"schema_summary\": \"...\"\n"
			. "}\n"
			. "Noi dung can chuyen doi:\n"
			. $article_html . "\n"
			. "Route context: from={$from}, to={$to}, slug={$slug}.";
	}

	private static function clean_generated_html( string $content ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}

		$decoded = self::parse_structured_payload( $content );
		if ( ! empty( $decoded['article_html'] ) ) {
			$content = (string) $decoded['article_html'];
		} elseif ( preg_match( '/"article_html"\s*:\s*"([\s\S]*?)"\s*(?:,|\})/s', $content, $m ) ) {
			$content = html_entity_decode( (string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$content = preg_replace( '/^```(?:json|html)?\s*/i', '', $content ) ?? $content;
		$content = preg_replace( '/\s*```$/', '', $content ) ?? $content;
		$content = str_replace( [ "\\r\\n", "\\n", "\\r", "\\t" ], [ "\n", "\n", "\n", "\t" ], $content );
		$content = str_replace( [ "\\\"", "\\/" ], [ '"', '/' ], $content );
		$content = preg_replace( '/^\s*"seo_title"\s*:\s*".*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*"meta_description"\s*:\s*".*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*"h1"\s*:\s*".*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*"slug_suggestion"\s*:\s*".*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*"article_html"\s*:\s*".*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*n{1,3}\s*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*\{\s*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*\}\s*$/mi', '', $content ) ?? $content;
		$content = preg_replace( "/\n{3,}/", "\n\n", $content ) ?? $content;

		return trim( $content );
	}

	private static function strip_json_envelope_from_content( string $content, array $structured = [] ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}
		if ( ! empty( $structured['article_html'] ) ) {
			return trim( (string) $structured['article_html'] );
		}
		if ( preg_match( '/"article_html"\s*:\s*"([^"]*)"/s', $content, $m ) ) {
			$candidate = str_replace( [ "\\r\\n", "\\n", "\\r", "\\t", "\\\"", "\\/" ], [ "\n", "\n", "\n", "\t", '"', '/' ], (string) $m[1] );
			if ( '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}
		if ( preg_match( '/^\s*\{[\s\S]*\}\s*(.+)$/s', $content, $m ) ) {
			return trim( (string) $m[1] );
		}
		if ( preg_match( '/^\s*```(?:json|html)?\s*([\s\S]*?)\s*```$/s', $content, $m ) ) {
			return trim( (string) $m[1] );
		}
		return $content;
	}

	private static function build_fallback_structured_payload( array $route, string $raw_content = '', string $topic = 'route_landing', array $length_profile = [], array $context = [] ): array {
		$from = (string) ( $route['from_city'] ?? '' );
		$to   = (string) ( $route['to_city'] ?? '' );
		$article = self::build_manual_article_html( $route, $topic, $length_profile, $context );
		return [
			'seo_title'             => self::route_title( $route ),
			'meta_description'      => self::build_meta_description( $route, $topic ),
			'h1'                    => self::route_title( $route ),
			'slug_suggestion'       => (string) ( $route['slug'] ?? '' ),
			'article_html'          => $article,
			'faq'                   => self::build_manual_faq( $route, $topic, $context ),
			'featured_image_prompt' => sprintf( 'Anh taxi thuc te cho tuyen %s di %s, phong cach mien Tay Viet Nam, sang ro, chuyen nghiep.', $from, $to ),
			'internal_links'        => [
				[
					'anchor' => sprintf( 'Xem them tuyen %s di %s', $from, $to ),
					'target' => (string) ( $route['booking_url'] ?: $route['landing_url'] ?: home_url( '/' ) ),
					'reason' => 'Internal link theo route chinh de giu nguyen intent di chuyen.',
				],
			],
			'schema_summary'        => 'Service + FAQPage',
		];
	}

	private static function build_meta_description( array $route, string $topic = 'route_landing' ): string {
		$from     = trim( (string) ( $route['from_city'] ?? '' ) );
		$to       = trim( (string) ( $route['to_city'] ?? '' ) );
		$distance = trim( (string) ( $route['distance_km'] ?? '' ) );
		$duration = trim( (string) ( $route['duration_min'] ?? '' ) );
		$price    = trim( (string) ( $route['price_display'] ?? '' ) );
		$topic    = TopicTemplateRegistry::sanitize_topic( $topic );

		$base = sprintf(
			'Taxi %s đi %s, %s km, %s phút, giá tham khảo %s. Đặt xe riêng, chủ động giờ giấc và phù hợp đi gia đình, công tác hoặc đi gấp.',
			$from,
			$to,
			$distance ?: 'dang cap nhat',
			$duration ?: 'dang cap nhat',
			$price ?: 'dang cap nhat'
		);

		switch ( $topic ) {
			case 'airport_route':
				$base = sprintf(
					'Taxi %s đi %s phù hợp cho khách cần chủ động giờ bay, hành lý và đón trả tận nơi. Giá tham khảo %s.',
					$from,
					$to,
					$price ?: 'dang cap nhat'
				);
				break;
			case 'hospital_route':
				$base = sprintf(
					'Taxi %s đi %s cho khách cần đi khám bệnh, người lớn tuổi hoặc cần đến bệnh viện sớm. Thời gian tham khảo %s phút, giá từ %s.',
					$from,
					$to,
					$duration ?: 'dang cap nhat',
					$price ?: 'dang cap nhat'
				);
				break;
			case 'business_trip':
				$base = sprintf(
					'Taxi %s đi %s phù hợp cho khách công tác cần lên lịch rõ ràng, đi đúng giờ và có giá tham khảo minh bạch %s.',
					$from,
					$to,
					$price ?: 'dang cap nhat'
				);
				break;
		}

		$base = preg_replace( '/\s+/', ' ', trim( $base ) ) ?? trim( $base );
		return wp_trim_words( $base, 30, '' );
	}

	private static function build_manual_article_html( array $route, string $topic = 'route_landing', array $length_profile = [], array $context = [] ): string {
		$from      = trim( (string) ( $route['from_city'] ?? '' ) );
		$to        = trim( (string) ( $route['to_city'] ?? '' ) );
		$slug      = (string) ( $route['slug'] ?? '' );
		$title     = self::route_title( $route );
		$distance  = trim( (string) ( $route['distance_km'] ?? '' ) );
		$duration  = trim( (string) ( $route['duration_min'] ?? '' ) );
		$price     = trim( (string) ( $route['price_display'] ?? '' ) );
		$booking   = trim( (string) ( $route['booking_url'] ?? '' ) );
		$veh_json  = json_decode( (string) ( $route['vehicle_prices_json'] ?? '[]' ), true );
		$vehicles  = is_array( $veh_json ) ? $veh_json : [];
		$faq_items = self::build_manual_faq( $route, $topic, $context );
		$primary   = trim( (string) ( $context['primary_keyword'] ?? '' ) );
		$intent    = trim( (string) ( $context['search_intent'] ?? '' ) );
		$extra     = trim( (string) ( $route['intro'] ?? '' ) );
		if ( '' === $extra ) {
			$extra = sprintf( 'Tuyến %s đi %s phù hợp cho khách đi công tác, gia đình, khách cần xe riêng và khách muốn chủ động giờ giấc.', $from, $to );
		}
		$lead_paragraph = $extra;
		$topic_sections  = [];
		switch ( $topic ) {
			case 'price_guide':
				$lead_paragraph = sprintf(
					'Bài này tập trung vào cách tính giá taxi tuyến %s đi %s, để bạn nắm được khoảng chi phí trước khi đặt xe và biết khi nào giá có thể thay đổi.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>Cách hiểu bảng giá taxi cho đúng</h2><p>Giá taxi tùy theo loại xe, thời điểm đi, số điểm đón và yêu cầu riêng. Nếu đi trong khung giờ dễ xe và cùng một điểm đón, mức giá thường ổn định hơn. Khi có thêm hành lý, đi đêm, hoặc cần xe lớn hơn, chi phí sẽ thay đổi rõ hơn.</p>';
				$topic_sections[] = '<h2>Loại xe nào hợp với từng mức chi phí</h2><p>Xe 4 chỗ hợp với người đi nhỏ lẻ, cần tiết kiệm. Xe 7 chỗ phù hợp hơn cho gia đình hoặc nhóm nhỏ có nhiều hành lý. Nếu đi đoàn đông, xe 16 chỗ giúp chia đầu chi phí và dễ sắp xếp lịch trình hơn.</p>';
				break;
			case 'comparison':
				$lead_paragraph = sprintf(
					'Tuyến %s đi %s thường có nhiều cách đi. Phần này giúp bạn so sánh taxi riêng với xe khách và xe hợp đồng để chọn cách phù hợp nhất.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>So sánh nhanh các lựa chọn đi lại</h2><table><thead><tr><th>Tiêu chí</th><th>Taxi riêng</th><th>Xe khách</th><th>Xe hợp đồng</th></tr></thead><tbody><tr><td>Chủ động giờ</td><td>Cao</td><td>Thấp</td><td>Trung bình</td></tr><tr><td>Riêng tư</td><td>Cao</td><td>Thấp</td><td>Trung bình</td></tr><tr><td>Đi nhiều điểm</td><td>Dễ</td><td>Khó</td><td>Có thể</td></tr></tbody></table>';
				$topic_sections[] = '<h2>Khi nào nên chọn taxi riêng</h2><p>Taxi riêng thích hợp khi bạn cần lên lịch rõ, có người lớn tuổi, trẻ nhỏ, hành lý, hoặc cần đến nhiều điểm trong cùng một chuyến đi. Xe khách phù hợp hơn nếu bạn ưu tiên tiết kiệm và không cần linh hoạt cao.</p>';
				break;
			case 'airport_route':
				$lead_paragraph = sprintf(
					'Với tuyến %s đi %s, vấn đề quan trọng nhất thường là giờ giấc và hành lý. Bài viết này tập trung vào cách đi sân bay an toàn, đúng giờ và có đủ thời gian dự phòng.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>Mẹo canh giờ và xử lý hành lý</h2><p>Nếu đi sân bay, bạn nên tính thêm thời gian dự phòng để tránh trễ giờ làm thủ tục. Khách có nhiều hành lý hoặc đi cùng trẻ nhỏ nên chọn xe rộng và xác nhận trước về khoang chứa đồ.</p>';
				break;
			case 'hospital_route':
				$lead_paragraph = sprintf(
					'Khi đi từ %s đến %s để khám chữa bệnh, điều cần nhất là sự ổn định và di chuyển dễ chịu. Bài này tập trung vào cách sắp xếp chuyến đi nhanh, sớm và yên tâm hơn.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>Lưu ý khi đi bệnh viện</h2><p>Với chuyến đi y tế, bạn nên báo trước điểm đón, số người đi và tình trạng của hành khách để tài xế sắp xếp chuẩn hơn. Xe êm ái và có chỗ ngồi thoải mái sẽ giúp hành trình dễ chịu hơn.</p>';
				break;
			case 'travel_guide':
				$lead_paragraph = sprintf(
					'Bài hướng dẫn này ghép hành trình %s đi %s với gợi ý đi lại thực tế, phù hợp cho khách du lịch muốn có một cung đường dễ theo dõi và dễ chấp nhận.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>Gợi ý hành trình và điểm dừng</h2><p>Nếu đi du lịch, bạn có thể kết hợp một vài điểm ghé ngang đường, ăn uống theo khung giờ hợp lý và cân đối thời gian nghỉ ngơi. Cách đi này hợp với khách gia đình, nhóm bạn hoặc khách đi trong 1 ngày.</p>';
				break;
			case 'food_guide':
				$lead_paragraph = sprintf(
					'Với tuyến %s đi %s, nhiều khách không chỉ quan tâm đến hành trình mà còn muốn biết nên ăn gì, dừng chân ở đâu và kết hợp đi lại thế nào cho hợp lý.',
					$from,
					$to
				);
				$topic_sections[] = '<h2>Đặc sản và điểm ăn uống</h2><p>Nếu tuyến này đi qua khu vực có đặc sản địa phương, bạn có thể ghé thăm một vài điểm ăn uống đơn giản, thực tế, và đúng với thời gian di chuyển. Bài viết nên nói rõ những gợi ý có khả năng đi được, không phải danh sách khoa trương.</p>';
				break;
		}

		$price_lines = [];
		if ( ! empty( $vehicles ) ) {
			foreach ( array_slice( $vehicles, 0, 4 ) as $vehicle ) {
				$name  = trim( (string) ( $vehicle['name'] ?? $vehicle['label'] ?? '' ) );
				$value = trim( (string) ( $vehicle['price'] ?? $vehicle['formatted_price'] ?? '' ) );
				if ( '' !== $name && '' !== $value ) {
					$price_lines[] = sprintf( '%s: %s', $name, $value );
				}
			}
		}
		if ( empty( $price_lines ) ) {
			$price_lines[] = sprintf( 'Gia tham khao cho tuyen nay hien dang o muc %s, tuy thuoc loai xe va thoi diem dat.', $price ?: 'tuy thuc te' );
			$price_lines[] = 'Xe 4 cho, 7 cho va xe lon hon se co muc gia khac nhau, nhat la vao gio cao diem hoac di dem.';
		}

		$body_parts = [];
		$body_parts[] = sprintf( '<h1>%s</h1>', esc_html( $title ) );
			$body_parts[] = sprintf(
				'<p>%s Tuyen nay co quang duong khoang %s km, thoi gian di thuong vao khoang %s phut neu duong thong thoang. Neu ban can chu dong theo gio don, di theo nhom nho, hoac can nghi ngoi rieng tu, taxi lien tinh la mot cach di lai de chiu hon xe ghep trong nhieu tinh huong.</p>',
				esc_html( $lead_paragraph ),
				esc_html( $distance ?: 'dang cap nhat' ),
				esc_html( $duration ?: 'dang cap nhat' )
			);

		$body_parts[] = '<h2>Gia taxi tuyen nay</h2>';
		$body_parts[] = sprintf(
			'<p>%s Gia co the thay doi theo loai xe, gio di, so diem don, cung nhu yeu cau doi lich. Vi vay, so tien hien thi nen duoc xem nhu muc tham khao de ban hinh dung khoang chi phi ban dau, con gia chot cuoi cung nen doi theo thong tin don thuc te.</p>',
			esc_html( $price ? 'Gia hien tham khao cua tuyen nay la ' . $price . '.' : 'Gia hien duoc tinh theo quang duong va loai xe.' )
		);
		$body_parts[] = '<ul>';
		foreach ( $price_lines as $line ) {
			$body_parts[] = '<li>' . esc_html( $line ) . '</li>';
		}
		$body_parts[] = '</ul>';

		$body_parts[] = '<h2>Quang duong va cach di</h2>';
		$body_parts[] = sprintf(
			'<p>Tuyen %s di %s thuong phu hop cho khach can di nhanh, khong muon doi chuyen nhieu lan, hoac co hanh ly can xep gon. Neu di vao buoi sang som hay cuoi gio chieu, ban nen can them thoi gian du phong cho viec don khach va di qua cac doan duong dong.</p>',
			esc_html( $from ),
			esc_html( $to )
		);
		$body_parts[] = sprintf(
			'<p>Voi nhung chuyen di gia dinh, nguoi lon tuoi, khach di kham benh hoac khach di cong tac, viec co xe rieng giup hanh trinh de theo doi hon. Ban chu dong duoc diem don, diem tra va co the trao doi truc tiep voi tai xe ve cac yeu cau phat sinh.</p>'
		);

		$body_parts[] = '<h2>Loai xe phu hop</h2>';
		$body_parts[] = '<p>Neu di mot minh hoac 2 nguoi, xe 4 cho thuong du gon va de dieu pho. Neu di gia dinh, nhieu hanh ly, di cung nguoi lon tuoi hoac co tre nho, xe 7 cho se thoai mai hon. Doan khach nhieu nguoi nen can dong xe som de dam bao co dung so ghe va khong bi dong lich vao gio cao diem.</p>';
		if ( ! empty( $primary ) ) {
			$body_parts[] = sprintf(
				'<p>Voi tu khoa chinh %s, noi dung nen giu giang di thuc te, tap trung vao hu cau dat xe ro rang, khong ep keyword vao tung doan.</p>',
				esc_html( $primary )
			);
		}

		$body_parts[] = '<h2>Khi nao nen di taxi rieng</h2>';
		$body_parts[] = '<p>Taxi rieng phu hop khi ban can len lich chi tiet, muon xuong trung tam, benh vien, san bay hoac diem hen cu the. Dac biet, cac chuyen di khoi hanh srom, di dem, hoac can don khach tai nhieu diem trong cung mot hanh trinh thuong se de sap xep hon neu co xe rieng.</p>';
		$body_parts[] = '<p>Voi nguoi lam viec di tinh, su on dinh ve gio giac thuong quan trong hon muc gia chenh lech mot chut. Voi gia dinh, dieu can nhat lai la su thoai mai va khong bi ep phai doi xe giua duong.</p>';

		$body_parts[] = '<h2>Diem dang luu y khi dat xe</h2>';
		$body_parts[] = '<p>Neu co thay doi ve so nguoi di, hanh ly lon, diem don ngoai tuyen hoac can xuat hoa don, ban nen bao truoc de tai xe chuan bi xe phu hop. Dieu nay giup han che phat sinh va giu cho gia cuoi cung ro rang hon.</p>';

		foreach ( $topic_sections as $section_html ) {
			$body_parts[] = $section_html;
		}

		$route_reviews = json_decode( (string) ( $route['reviews_json'] ?? '[]' ), true );
		if ( is_array( $route_reviews ) && ! empty( $route_reviews ) ) {
			$review_lines = [];
			foreach ( array_slice( $route_reviews, 0, 2 ) as $review ) {
				$reviewer = trim( (string) ( $review['name'] ?? $review['author'] ?? '' ) );
				$reviewer = '' !== $reviewer ? $reviewer : 'Khach da di';
				$text     = trim( (string) ( $review['content'] ?? $review['text'] ?? '' ) );
				if ( '' !== $text ) {
					$review_lines[] = sprintf( '<blockquote><p>%s</p><cite>%s</cite></blockquote>', esc_html( $text ), esc_html( $reviewer ) );
				}
			}
			if ( ! empty( $review_lines ) ) {
				$body_parts[] = '<h2>Mot so cam nhan thuc te</h2>';
				$body_parts[] = '<p>Nguoi dung thuong quan tam den su on dinh cua tai xe, cach don tra va muc do chu dong trong ca chuyen di. Neu tuyen nay co san nhan xet noi bo, chung toi uu tien dung nhu lieu thuc te de bai viet co chieu sau hon.</p>';
				foreach ( $review_lines as $review_line ) {
					$body_parts[] = $review_line;
				}
			}
		}

		$body_parts[] = '<h2>Cau hoi thuong gap</h2>';
		$body_parts[] = '<div class="srt-faq">';
		foreach ( $faq_items as $item ) {
			$q = esc_html( (string) ( $item['question'] ?? '' ) );
			$a = esc_html( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$body_parts[] = '<details><summary>' . $q . '</summary><p>' . $a . '</p></details>';
		}
		$body_parts[] = '</div>';

		$body_parts[] = '<h2>Dat xe va giu lich</h2>';
		$body_parts[] = sprintf(
			'<p>Nếu ban can di som, di cuoi tuan hoac can giu xe truoc, nen lien he som de co tai xe va loai xe phu hop. %s</p>',
			$booking ? sprintf( 'Ban co the dat nhanh qua lien ket <a href="%s">tai day</a>.', esc_url( $booking ) ) : 'Ban co the lien he truc tiep de chot lich di va xac nhan gia cuoi cung.'
		);

		$body_parts[] = sprintf(
			'<p>[srt_similar_routes slug="%s"]</p>',
			esc_attr( $slug )
		);

		$body_parts[] = sprintf(
			'<p>Tuyen nay co the phu hop voi khach %s, %s va cac chuyen di can su chu dong ve gio giac. Noi dung duoc viet theo huong thuc te, khong phai mau van chung chung.</p>',
			esc_html( $intent ?: 'can di nhanh' ),
			esc_html( $from && $to ? sprintf( '%s - %s', $from, $to ) : 'liên tỉnh' )
		);

		$content = implode( "\n", $body_parts );
		$target  = max( 900, (int) ( $length_profile['min'] ?? 900 ) + 180 );
		$sentences = [
			sprintf( 'Thuc te, khach di tuyen %s di %s thuong can mot bai viet de hieu, co so lieu ro rang va khong lan man.', $from, $to ),
			sprintf( 'Ban co the xem gia tham khao la moc ban dau, con cuoc cuoi cung se phu thuoc vao xe, gio di va yeu cau phat sinh.', ),
			sprintf( 'Neu di cung nguoi than, viec co xe rieng giup hanh trinh thoa mai hon va de dung nhung luc can nghi yen tam.', ),
			sprintf( 'Voi khach di cong tac, dieu quan trong la dung gio va co len lich ro rang, hon la chon mot bai viet qua quang cao.', ),
		];
		$counter = 0;
		while ( self::count_words_plain( $content ) < $target && $counter < 10 ) {
			$content .= "\n<p>" . esc_html( $sentences[ $counter % count( $sentences ) ] ) . '</p>';
			$counter++;
		}

		return trim( $content );
	}

	private static function build_manual_faq( array $route, string $topic = 'route_landing', array $context = [] ): array {
		$from = trim( (string) ( $route['from_city'] ?? '' ) );
		$to   = trim( (string) ( $route['to_city'] ?? '' ) );
		$price = trim( (string) ( $route['price_display'] ?? '' ) );

		$faq = [
			[
				'question' => sprintf( 'Di tu %s den %s mat bao lau?', $from, $to ),
				'answer'   => sprintf( 'Thoi gian thuong phu thuoc vao luong xe, diem don va yeu cau dung do. Voi tuyen nay, thoi gian tham khao hien tai la %s phut neu duong thong thoang.', trim( (string) ( $route['duration_min'] ?? 'dang cap nhat' ) ) ),
			],
			[
				'question' => 'Gia co co dinh khong?',
				'answer'   => $price ? sprintf( 'Gia hien co muc tham khao la %s, nhung gia cuoi cung van nen xac nhan lai theo loai xe, gio di va diem don.', $price ) : 'Gia thuong duoc tinh theo quang duong va loai xe. Ban nen xac nhan lai truoc khi chot lich.',
			],
			[
				'question' => 'Co don tai nha hay tai diem hen khong?',
				'answer'   => 'Co. Phan lon chuyen di lien tinh se co the don theo diem hen cu the neu duong di va lich trinh cho phep.',
			],
			[
				'question' => 'Co phu hop cho nguoi lon tuoi va gia dinh khong?',
				'answer'   => 'Co. Xe rieng giup di chuyen chu dong, hang ghe ro rang, va de sap xep cac diem nghi khi can.',
			],
			[
				'question' => 'Co the dat xe som de giu lich khong?',
				'answer'   => 'Nen dat som neu di vao cuoi tuan, ngay le, hoac can xe vao khung gio sang som va ban dem.',
			],
		];

		$route_faqs = json_decode( (string) ( $route['faqs_json'] ?? '[]' ), true );
		if ( is_array( $route_faqs ) ) {
			foreach ( array_slice( $route_faqs, 0, 4 ) as $item ) {
				$q = trim( (string) ( $item['question'] ?? $item['q'] ?? '' ) );
				$a = trim( (string) ( $item['answer'] ?? $item['a'] ?? '' ) );
				if ( '' !== $q && '' !== $a ) {
					$faq[] = [ 'question' => $q, 'answer' => $a ];
				}
			}
		}

		if ( 'airport_route' === $topic ) {
			$faq[] = [
				'question' => 'Co the can gio di san bay khong?',
				'answer'   => 'Co. Nhung chuyen di san bay nen co them thoi gian du phong de tranh tre gio lam thu tuc.',
			];
		}

		if ( 'hospital_route' === $topic ) {
			$faq[] = [
				'question' => 'Xe co phu hop khi can di kham benh som khong?',
				'answer'   => 'Co. Di xe rieng giup chu dong, voi nguoi lon tuoi hoac khach can den benh vien som thi day la chon phu hop.',
			];
		}

		return array_values( array_filter( $faq ) );
	}

	private static function count_words_plain( string $text ): int {
		$text = wp_strip_all_tags( $text );
		if ( '' === trim( $text ) ) {
			return 0;
		}
		if ( preg_match_all( '/\p{L}[\p{L}\p{M}\p{N}_-]*/u', $text, $m ) ) {
			return count( $m[0] );
		}
		return str_word_count( $text );
	}

	private static function build_topic_guidance( array $route, array $context ): string {
		$topic = TopicTemplateRegistry::sanitize_topic( (string) ( $context['topic_id'] ?? 'route_landing' ) );
		$meta  = TopicTemplateRegistry::get( $topic );
		$sections = implode( ', ', (array) ( $meta['required_sections'] ?? [] ) );
		$forbidden = implode( ', ', (array) ( $meta['forbidden_patterns'] ?? [] ) );
		$schema = implode( ', ', (array) ( $meta['schema'] ?? [] ) );
		$profile = self::topic_profile_guidance( $topic, $route );
		$length_guidance = self::length_profile_guidance( (string) ( $context['content_length'] ?? 'standard' ), $context );
		$opening_strategy = self::opening_strategy_guidance( $topic );

		return "\n\nTOPIC GUIDANCE:\n"
			. '- topic_id: ' . $topic . "\n"
			. '- topic_label: ' . (string) ( $meta['label'] ?? '' ) . "\n"
			. '- search_intent: ' . (string) ( $meta['intent'] ?? '' ) . "\n"
			. '- recommended_length: ' . (string) ( $context['content_length'] ?? ( $meta['length_default'] ?? 'standard' ) ) . "\n"
			. '- length_guidance: ' . $length_guidance . "\n"
			. '- opening_strategy: ' . $opening_strategy . "\n"
			. '- required_sections: ' . $sections . "\n"
			. '- section_sequence: ' . self::render_section_sequence( (array) ( $meta['required_sections'] ?? [] ) ) . "\n"
			. '- forbidden_patterns: ' . $forbidden . "\n"
			. '- schema_types: ' . $schema . "\n"
			. '- cta_style: ' . (string) ( $meta['cta_style'] ?? '' ) . "\n"
			. '- route_context: ' . (string) ( $route['from_city'] ?? '' ) . ' -> ' . (string) ( $route['to_city'] ?? '' ) . "\n"
			. '- topic_profile: ' . $profile . "\n"
			. "Rules:\n"
			. "0) Bat buoc viet tieng Viet co dau day du, khong duoc bo dau, khong duoc tra ve ASCII-only.\n"
			. "1) Keep tone natural, local, and practical.\n"
			. "2) Vary section order and transition language by topic. Do not reuse the same intro for every route.\n"
			. "3) Mention only facts present in route data. If a fact is missing, phrase it as an estimate or omit it.\n"
			. "4) Avoid opening every section with the same phrase.\n"
			. "5) Do not output markdown fences or extra commentary outside JSON when JSON is requested.\n"
			. "6) Avoid generic sales phrases and repetitive transitions.\n";
	}

	private static function topic_profile_guidance( string $topic, array $route ): string {
		$from = (string) ( $route['from_city'] ?? '' );
		$to   = (string) ( $route['to_city'] ?? '' );
		switch ( $topic ) {
			case 'price_guide':
				return 'tap trung vao cach tinh gia, cac yeu to lam gia thay doi, so sanh xe 4 cho vs 7 cho, va meo dat xe de co gia de chiu';
			case 'travel_guide':
				return 'viet nhu huong dan di duong that, gop canh di lai, diem dung, thoi diem phu hop, va mot vai goi y cho khach du lich';
			case 'food_guide':
				return 'ghep hanh trinh voi dac san dia phuong, diem an uong theo cung duong, va chi noi nhung mon co kha nang ton tai that';
			case 'destination_guide':
				return 'tap trung vao diem den, cach di, thoi gian nen di, va vi sao taxi rieng hop cho hanh trinh nay';
			case 'hospital_route':
				return 'uu tien tinh can thi, di som, don benh vien, xe em ai, va loi van ngan gon de khach de doc';
			case 'airport_route':
				return 'nhan manh gio giac, hanh ly, du phong thoi gian, dat xe truoc, va meo tranh tre gio bay';
			case 'business_trip':
				return 'giong van phong gon, co kinh nghiem cong tac, schedule control, invoice notes, va khong qua cuoc hoa';
			case 'family_trip':
				return 'noi ve do an toan, xe rong, tre nho, nguoi lon tuoi, ghe nghi hop ly, va di chuyen thoa mai';
			case 'wedding_event':
				return 'tap trung dat xe doan, timing, di nhieu nguoi, trang trong, va co lich trinh ro rang';
			case 'pilgrimage':
				return 'giong nguoi quen duong, di chua/hanh huong, di som, de hieu, va ton trong khong gian ton giao';
			case 'weekend_itinerary':
				return 'co goi y lich trinh, diem ghe tham, an uong, va cach can doi di chuyen trong 2-3 ngay';
			case 'budget_tips':
				return 'giai thich cach tiet kiem, chon xe, chon gio, dat som, va chia se meo thuc te';
			case 'local_experience':
				return 'ke kinh nghiem nguoi dia phuong, duong di, lich gio, meo nho, va chi tiet thuc te';
			case 'comparison':
				return 'so sanh taxi rieng voi xe khach/xe hop dong theo tieu chi ro rang, can bang, co nhan dinh thuc te';
			case 'faq_article':
				return 'tra loi cau hoi that, ngan gon ma co chieu sau, moi FAQ phai khac nhau ve noi dung';
			case 'seasonal_content':
				return 'co canh bao mua cao diem, le, Tet, dat xe som va thay doi lich di';
			case 'route_cluster':
				return 'lam bai hub, lien ket cac tuyen gan nhau, co internal links, va khong lap bai';
			default:
				return 'route landing can local intent, gia, quang duong, thoi gian, xe phu hop, va CTA mem';
		}
	}

	private static function length_profile_guidance( string $length, array $context ): string {
		$length = sanitize_key( $length );
		$min    = (int) ( $context['min_words'] ?? 0 );
		$max    = (int) ( $context['max_words'] ?? 0 );
		$range  = $min > 0 && $max > 0 ? $min . '-' . $max . ' words' : 'use the requested profile';

		switch ( $length ) {
			case 'short':
				return 'gach 5 section, ngan gon, tap trung vao gia + thoi gian + CTA mem, khong lan man';
			case 'standard':
				return '6-7 section, co intro, price, distance, vehicle, FAQ, CTA, giu nhịp doc de thuong';
			case 'long':
				return '7-9 section, them insight dia phuong, comparison nho, FAQ sau hon, ' . $range;
			case 'deep':
				return 'pillar style, 9+ section, co context local, internal links, comparison, FAQ, va chi tiet day du, ' . $range;
			case 'custom':
				return 'follow custom word range ' . $range . ', giu cau truc ro rang va co chieu sau';
			default:
				return 'follow ' . $range . ' with balanced route article structure';
		}
	}

	private static function opening_strategy_guidance( string $topic ): string {
		switch ( $topic ) {
			case 'price_guide':
				return 'mo bai bang cau hoi ve chi phi va muc gia tham khao, khong mo dau bang loi chao rung rong';
			case 'comparison':
				return 'mo bai bang so sanh lua chon di lai va tieu chi quyet dinh, khong mo dau bang giai thich chung chung';
			case 'airport_route':
				return 'mo bai bang gio giac, hanh ly, va moi quan tam den dung gio';
			case 'hospital_route':
				return 'mo bai bang nhu cau di som, di gap va tinh on dinh';
			case 'travel_guide':
				return 'mo bai bang trai nghiem hanh trinh va goi y di lai thuc te';
			case 'food_guide':
				return 'mo bai bang cau chuyen an uong theo cung duong va cac diem dung hop ly';
			case 'family_trip':
				return 'mo bai bang su an toan va tien loi cho gia dinh';
			default:
				return 'mo bai bang nhu cau di lai thuc te cua route, khong dung mo dau mau co dinh';
		}
	}

	private static function render_section_sequence( array $sections ): string {
		$sections = array_values( array_filter( array_map( 'sanitize_key', $sections ) ) );
		if ( empty( $sections ) ) {
			return 'intro -> price -> distance -> vehicle -> faq -> cta';
		}
		return implode( ' -> ', $sections );
	}

	private static function remove_broken_faq_markup( string $content ): string {
		$content = preg_replace( '/<div\s+class="faq[^>]*>[\s\S]*$/i', '', $content ) ?? $content;
		$content = preg_replace( '/^\s*<div\s+class="faq.*$/mi', '', $content ) ?? $content;
		$content = preg_replace( '/<h2[^>]*>.*FAQ.*<\/h2>/iu', '', $content ) ?? $content;
		return trim( $content );
	}

	private static function append_structured_faq_html( string $content, array $faq ): string {
		if ( empty( $faq ) ) {
			return $content;
		}
		$html = "\n\n<h2>FAQ</h2>\n<div class=\"srt-faq\">\n";
		foreach ( $faq as $item ) {
			$q = esc_html( (string) ( $item['question'] ?? '' ) );
			$a = esc_html( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$html .= "<details><summary>{$q}</summary><p>{$a}</p></details>\n";
		}
		$html .= "</div>\n";
		return trim( $content ) . $html;
	}

	private static function maybe_restore_vietnamese_diacritics( string $content, array $route, array $context = [] ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return $content;
		}
		if ( ! self::needs_vietnamese_diacritics_rewrite( $content ) ) {
			return $content;
		}
		if ( ! empty( $context['force_ascii_output'] ) ) {
			return $content;
		}

		$config = AIConfig::get();
		if ( empty( $config['enable_content'] ) ) {
			return $content;
		}

		$prompt = "Hay viet lai doan HTML sau sang tieng Viet co dau day du, giu nguyen y nghia, giu nguyen cau truc HTML, khong them y moi, khong doi so lieu, khong output markdown hay code fence. Chi tra ve HTML.\n\n"
			. "Route: " . (string) ( $route['from_city'] ?? '' ) . ' -> ' . (string) ( $route['to_city'] ?? '' ) . "\n\n"
			. $content;
		$response = AIService::provider()->generate_text( $prompt );
		if ( ! empty( $response['success'] ) && ! empty( $response['content'] ) ) {
			$rewritten = self::clean_generated_html( (string) $response['content'] );
			if ( '' !== trim( $rewritten ) ) {
				return $rewritten;
			}
		}
		return $content;
	}

	private static function contains_vietnamese_diacritics( string $content ): bool {
		return (bool) preg_match( '/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $content );
	}

	private static function needs_vietnamese_diacritics_rewrite( string $content ): bool {
		if ( ! self::contains_vietnamese_diacritics( $content ) ) {
			return true;
		}
		$ascii_markers = [
			'\bTuyen\b',
			'\bQuang duong\b',
			'\bGia taxi\b',
			'\bThoi gian\b',
			'\bKhach\b',
			'\bBenh vien\b',
			'\bDat xe\b',
			'\bGiu lich\b',
			'\bDiem don\b',
			'\bDiem tra\b',
			'\bNguoi lon tuoi\b',
			'\bTre nho\b',
			'\bSan bay\b',
			'\bTuyen nay co\b',
			'\bGia co the\b',
		];
		foreach ( $ascii_markers as $marker ) {
			if ( preg_match( '/' . $marker . '/i', $content ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_common_vietnamese_phrases( string $content ): string {
		$replacements = [
			'/\bTuyen\b/u' => 'Tuyến',
			'/\bQuang duong\b/u' => 'Quãng đường',
			'/\bGia taxi\b/u' => 'Giá taxi',
			'/\bGia hien\b/u' => 'Giá hiện',
			'/\bGia co the\b/u' => 'Giá có thể',
			'/\bGia\b/u' => 'Giá',
			'/\bThoi gian\b/u' => 'Thời gian',
			'/\bKhach\b/u' => 'Khách',
			'/\bBenh vien\b/u' => 'Bệnh viện',
			'/\bSan bay\b/u' => 'Sân bay',
			'/\bDat xe\b/u' => 'Đặt xe',
			'/\bGiu lich\b/u' => 'Giữ lịch',
			'/\bDiem don\b/u' => 'Điểm đón',
			'/\bDiem tra\b/u' => 'Điểm trả',
			'/\bNguoi lon tuoi\b/u' => 'Người lớn tuổi',
			'/\bTre nho\b/u' => 'Trẻ nhỏ',
			'/\bGia dinh\b/u' => 'Gia đình',
			'/\bCong tac\b/u' => 'Công tác',
			'/\bThoai mai\b/u' => 'Thoải mái',
			'/\bHanh ly\b/u' => 'Hành lý',
			'/\bDon tan noi\b/u' => 'Đón tận nơi',
			'/\bChu dong\b/u' => 'Chủ động',
			'/\bCanh bao\b/u' => 'Cảnh báo',
			'/\bTham khao\b/u' => 'Tham khảo',
			'/\bPhu hop\b/u' => 'Phù hợp',
			'/\bLien he\b/u' => 'Liên hệ',
			'/\bChot lich\b/u' => 'Chốt lịch',
			'/\bQuy khach\b/u' => 'Quý khách',
			'/\bLich trinh\b/u' => 'Lịch trình',
		];
		return preg_replace( array_keys( $replacements ), array_values( $replacements ), $content ) ?? $content;
	}

	private static function edit_url( int $post_id ): string {
		$url = get_edit_post_link( $post_id, 'raw' );
		return $url ? (string) $url : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	}

	private static function maybe_update_seo_meta( int $post_id, string $title, string $content ): void {
		$description = wp_trim_words( wp_strip_all_tags( $content ), 28 );
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, 'rank_math_description', $description );
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		}
	}

	private static function auto_assign_seo_tags( int $post_id, array $route, string $topic, string $primary_keyword, string $secondary_keywords ): void {
		$from = trim( (string) ( $route['from_city'] ?? '' ) );
		$to   = trim( (string) ( $route['to_city'] ?? '' ) );
		$tags = array_filter(
			[
				$from ? sprintf( 'Taxi %s', $from ) : '',
				$to ? sprintf( 'Taxi %s', $to ) : '',
				( $from && $to ) ? sprintf( 'Taxi %s di %s', $from, $to ) : '',
				'taxi lien tinh',
				'taxi mien tay',
				str_replace( '_', ' ', $topic ),
			]
		);
		if ( '' !== $primary_keyword ) {
			$tags[] = $primary_keyword;
		}
		if ( '' !== $secondary_keywords ) {
			$parts = preg_split( '/[\r\n,]+/', $secondary_keywords ) ?: [];
			foreach ( $parts as $part ) {
				$tag = trim( (string) $part );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}
		$tags = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tags ) ) ) );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, false );
		}
	}

	private static function find_duplicate_content_hash( string $hash, int $ignore_post_id = 0 ): int {
		$posts = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'       => '_srt_content_hash',
				'meta_value'     => $hash,
				'numberposts'    => 2,
				'fields'         => 'ids',
			]
		);
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 && $post_id !== $ignore_post_id ) {
				return $post_id;
			}
		}
		return 0;
	}
}
