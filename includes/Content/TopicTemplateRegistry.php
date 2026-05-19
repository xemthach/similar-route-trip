<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

defined( 'ABSPATH' ) || exit;

final class TopicTemplateRegistry {
	public static function all(): array {
		return [
			'route_landing' => self::topic(
				'Route Landing',
				'Trang đích chính cho từng tuyến taxi, nhấn mạnh giá, quãng đường, thời gian và CTA đặt xe.',
				'transactional local SEO',
				'standard',
				[ 1000, 1400 ],
				[ 'intro', 'price', 'distance', 'vehicle', 'booking_advice', 'faq', 'cta' ],
				[ 'generic marketing', 'fake local claim', 'keyword stuffing' ],
				[ 'Service', 'FAQPage' ],
				'soft booking intent'
			),
			'price_guide' => self::topic(
				'Price Guide',
				'Bài giải thích giá taxi, yếu tố ảnh hưởng giá và cách ước lượng chi phí trước khi đặt.',
				'commercial investigation',
				'standard',
				[ 1000, 1400 ],
				[ 'price_overview', 'price_factors', 'vehicle_comparison', 'booking_tips', 'faq' ],
				[ 'absolute price guarantee', 'vague pricing' ],
				[ 'Article', 'FAQPage' ],
				'consult before booking'
			),
			'travel_guide' => self::topic(
				'Travel Guide',
				'Bài hướng dẫn hành trình, điểm dừng và kinh nghiệm di chuyển địa phương.',
				'informational',
				'long',
				[ 1600, 2200 ],
				[ 'overview', 'places_to_visit', 'best_time', 'transport_options', 'suggested_route', 'faq' ],
				[ 'tourist brochure tone', 'generic filler' ],
				[ 'Article' ],
				'suggest route inquiry'
			),
			'food_guide' => self::topic(
				'Food Guide',
				'Bài gợi ý ẩm thực địa phương và cách kết hợp tuyến đi với điểm ăn uống thật.',
				'local discovery',
				'standard',
				[ 1000, 1400 ],
				[ 'local_specialties', 'eating_tips', 'travel_pairing', 'route_suggestion', 'faq' ],
				[ 'hallucinated specialties', 'spammy list' ],
				[ 'Article' ],
				'soft route plus food pairing'
			),
			'destination_guide' => self::topic(
				'Destination Guide',
				'Bài giới thiệu điểm đến, khu du lịch, bệnh viện hoặc sân bay gắn với tuyến cụ thể.',
				'informational',
				'long',
				[ 1600, 2200 ],
				[ 'overview', 'why_visit', 'how_to_get_there', 'best_time', 'faq' ],
				[ 'unverified attractions' ],
				[ 'Article' ],
				'discover and book'
			),
			'hospital_route' => self::topic(
				'Hospital Route',
				'Bài cho nhu cầu đi bệnh viện, khám chữa bệnh, cần đi sớm và giữ nhịp ổn định.',
				'urgent transactional',
				'standard',
				[ 1000, 1400 ],
				[ 'intro', 'time_sensitive_tips', 'vehicle_choice', 'pickup_notes', 'faq', 'cta' ],
				[ 'too-salesy', 'casual tone' ],
				[ 'Service', 'FAQPage' ],
				'urgent but calm'
			),
			'airport_route' => self::topic(
				'Airport Route',
				'Bài cho tuyến đi sân bay, hành lý, giờ bay và thời gian dự phòng.',
				'transactional travel',
				'standard',
				[ 1000, 1400 ],
				[ 'intro', 'luggage_tips', 'time_buffer', 'vehicle_choice', 'faq', 'cta' ],
				[ 'late pickup claim', 'overpromising traffic' ],
				[ 'Service', 'FAQPage' ],
				'book ahead'
			),
			'business_trip' => self::topic(
				'Business Trip',
				'Bài phục vụ khách công tác, ưu tiên đúng giờ, hóa đơn và lịch trình rõ ràng.',
				'commercial / informational',
				'long',
				[ 1600, 2200 ],
				[ 'intro', 'schedule_control', 'invoice_notes', 'vehicle_choice', 'faq' ],
				[ 'too playful', 'buzzword heavy' ],
				[ 'Article', 'FAQPage' ],
				'professional booking'
			),
			'family_trip' => self::topic(
				'Family Trip',
				'Bài cho gia đình, trẻ nhỏ, người lớn tuổi và sự thoải mái khi đi đường dài.',
				'informational / transactional',
				'standard',
				[ 1000, 1400 ],
				[ 'intro', 'comfort', 'safety', 'vehicle_choice', 'faq', 'cta' ],
				[ 'ignore elderly', 'ignore children' ],
				[ 'Article', 'FAQPage' ],
				'family friendly'
			),
			'wedding_event' => self::topic(
				'Wedding Event',
				'Bài cho đám cưới, tiệc, sự kiện, đặt xe theo đoàn và giữ lịch rõ ràng.',
				'transactional event transport',
				'standard',
				[ 1000, 1400 ],
				[ 'intro', 'group_transport', 'timing', 'vehicle_choice', 'faq' ],
				[ 'casual event tone' ],
				[ 'Service', 'FAQPage' ],
				'reserve in advance'
			),
			'pilgrimage' => self::topic(
				'Pilgrimage',
				'Bài cho chuyến đi chùa, lễ hội, hành hương và tuyến có yếu tố văn hoá địa phương.',
				'informational / transactional',
				'long',
				[ 1600, 2200 ],
				[ 'intro', 'route_notes', 'rest_stops', 'vehicle_choice', 'faq' ],
				[ 'disrespectful tone' ],
				[ 'Article', 'FAQPage' ],
				'book early'
			),
			'weekend_itinerary' => self::topic(
				'Weekend Itinerary',
				'Bài lên lịch cuối tuần, 2-3 ngày, kết hợp di chuyển và trải nghiệm.',
				'informational',
				'long',
				[ 1600, 2200 ],
				[ 'overview', 'day_plan', 'transport', 'food_spots', 'faq' ],
				[ 'generic itinerary', 'no transport context' ],
				[ 'Article' ],
				'plan the trip'
			),
			'budget_tips' => self::topic(
				'Budget Tips',
				'Bài mẹo tiết kiệm chi phí taxi, chọn giờ, chọn xe và tránh phát sinh.',
				'commercial investigation',
				'standard',
				[ 1000, 1400 ],
				[ 'price_factors', 'how_to_save', 'vehicle_choice', 'faq' ],
				[ 'fake discount', 'overclaim savings' ],
				[ 'Article' ],
				'compare before booking'
			),
			'local_experience' => self::topic(
				'Local Experience',
				'Bài kinh nghiệm thực tế của người địa phương trên tuyến đường cụ thể.',
				'informational / experiential',
				'long',
				[ 1600, 2200 ],
				[ 'story', 'local_context', 'route_notes', 'faq' ],
				[ 'copied brochure tone' ],
				[ 'Article' ],
				'soft contact'
			),
			'comparison' => self::topic(
				'Comparison',
				'Bài so sánh taxi riêng, xe khách và xe hợp đồng theo nhu cầu thật.',
				'commercial investigation',
				'standard',
				[ 1000, 1400 ],
				[ 'comparison_table', 'pros_cons', 'use_cases', 'faq' ],
				[ 'one-sided sales pitch' ],
				[ 'Article', 'FAQPage' ],
				'help user choose'
			),
			'faq_article' => self::topic(
				'FAQ Article',
				'Bài hỏi đáp chuyên sâu, giải đáp các câu hỏi thường gặp của khách đặt xe.',
				'support / informational',
				'standard',
				[ 1000, 1400 ],
				[ 'faq', 'short_answers', 'booking_cta' ],
				[ 'generic faq' ],
				[ 'FAQPage', 'Article' ],
				'supportive'
			),
			'seasonal_content' => self::topic(
				'Seasonal Content',
				'Bài theo mùa, lễ, Tết hoặc cao điểm đặt xe, có cảnh báo thời gian thực tế.',
				'timely informational',
				'standard',
				[ 1000, 1400 ],
				[ 'season_context', 'booking_timing', 'travel_notes', 'faq' ],
				[ 'timeless claims' ],
				[ 'Article' ],
				'season aware'
			),
			'route_cluster' => self::topic(
				'Route Cluster',
				'Bài hub gom các tuyến liên quan để tăng topical authority và internal links.',
				'topical authority',
				'deep',
				[ 2500, 3500 ],
				[ 'cluster_overview', 'route_list', 'internal_links', 'faq' ],
				[ 'duplicate route body' ],
				[ 'Article', 'ItemList' ],
				'browse related routes'
			),
		];
	}

	public static function get( string $topic ): array {
		$all = self::all();
		return $all[ $topic ] ?? $all['route_landing'];
	}

	public static function sanitize_topic( string $topic ): string {
		$topic = sanitize_key( $topic );
		return array_key_exists( $topic, self::all() ) ? $topic : 'route_landing';
	}

	private static function topic( string $label, string $description, string $intent, string $length_default, array $recommended_words, array $required_sections, array $forbidden_patterns, array $schema, string $cta_style ): array {
		return [
			'label' => $label,
			'description' => $description,
			'intent' => $intent,
			'length_default' => $length_default,
			'recommended_words' => $recommended_words,
			'required_sections' => $required_sections,
			'forbidden_patterns' => $forbidden_patterns,
			'schema' => $schema,
			'cta_style' => $cta_style,
		];
	}
}
