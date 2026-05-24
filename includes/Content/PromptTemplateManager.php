<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

defined( 'ABSPATH' ) || exit;

final class PromptTemplateManager {
	public const OPTION = 'srt_prompt_templates';
	public const VERSION_OPTION = 'srt_prompt_templates_version';

	public static function defaults(): array {
		return [
						'route_landing' => "ROLE: Senior SEO Content Strategist + Local SEO Copywriter + AI Prompt Engineer.\n\nNhiem vu: viet bai SEO chuyen sau cho tuyen taxi {{route.from}} di {{route.to}} theo local intent mien Tay, giong nguoi trong nghe viet, khong generic.\n\nABSOLUTE RULES:\n- Khong van mau vo hon, khong slogan rong.\n- Khong lap cau truc giua cac topic.\n- Khong spam keyword, khong nhai lai mot mo cau cho moi section.\n- Khong bia so lieu route. Chi dung du lieu da co.\n- Neu chua chac gia/khoang cach/thoi gian, phai ghi ro la tham khao.\n- Khong markdown, khong code fence.\n\nTARGET DOC GOC:\n- Nguoi di cong tac\n- Gia dinh di tinh\n- Nguoi lon tuoi\n- Khach di benh vien\n- Khach di san bay\n- Nhom du lich mien Tay\n- Nguoi can di gap ban dem\n\nROUTE DATA:\nfrom={{route.from}}\nto={{route.to}}\nslug={{route.slug}}\ndistance={{route.distance}}\nduration={{route.duration}}\nprice={{route.price}}\nformatted_price={{route.formatted_price}}\nvehicle_prices={{route.vehicle_prices}}\nsimilar_routes={{route.similar_routes}}\nsite_name={{site.name}}\nsite_phone={{site.phone}}\nservice_area={{site.service_area}}\ncontent_length={{content.length}}\nmin_words={{content.min_words}}\nmax_words={{content.max_words}}\nprimary_keyword={{seo.primary_keyword}}\nsecondary_keywords={{seo.secondary_keywords}}\nsearch_intent={{seo.search_intent}}\n\nCONTENT GOALS:\n1) SEO: semantic relevance, long-tail coverage, local route intent.\n2) Conversion mem: ro gia, ro quy trinh, de dat xe, khong sales lo lieu.\n3) EEAT: giong nguoi lam dich vu lau nam, thuc te, de hieu.\n\nSECTION LOGIC:\n- intro phai khac nhau theo topic va theo route.\n- moi topic phai co 1 cach mo bai rieng, khong clone.\n- bai long/deep phai co them insight, comparison nho va route note thuc te.\n- FAQ phai khac nhau, khong copy ngan cho cac route khac.\n\nBAT BUOC SECTION:\n- H1\n- Intro mo bai\n- Gia taxi tuyen\n- Quang duong + thoi gian\n- Loai xe phu hop\n- Khi nao nen di taxi rieng\n- Diem noi bat cua tuyen\n- FAQ\n- CTA mem\n- Similar routes/internal links\n\nYEU CAU QUALITY:\n- Cau van tu nhien, do dai cau da dang.\n- Tranh lap lai cum tu mo dau cau.\n- Khong dung qua nhieu bullet.\n- Co context local mien Tay neu phu hop.\n- Mobile-friendly, de quet doc.\n- Bai phai dat dung muc do dai {{content.length}} trong khoang {{content.min_words}}-{{content.max_words}} tu.\n\nOUTPUT BAT BUOC: tra ve JSON object duy nhat theo schema:\n{\n  \"seo_title\": \"...\",\n  \"meta_description\": \"...\",\n  \"h1\": \"...\",\n  \"slug_suggestion\": \"...\",\n  \"article_html\": \"...\",\n  \"faq\": [{\"question\":\"...\",\"answer\":\"...\"}],\n  \"featured_image_prompt\": \"...\",\n  \"internal_links\": [{\"anchor\":\"...\",\"target\":\"...\",\"reason\":\"...\"}],\n  \"schema_summary\": \"...\"\n}\n\nRANG BUOC:\n- article_html phai la HTML-ready.\n- FAQ phai thuc te, hoi dap tu nhien.\n- featured_image_prompt phai realistic, Vietnam Mekong Delta, taxi transportation, natural lighting.\n- internal_links uu tien route lien quan tu {{route.similar_routes}}.\n- Khong them bat ky text nao ngoai JSON.",
			'route_faq' => "Tra ve JSON array FAQ cho tuyen {{route.from}} di {{route.to}}.\nSo luong 8 cau hoi dap thuc te, giong hoi dap cua khach dat xe lien tinh.\nMoi answer 40-90 tu, ro rang va de hieu.\nChu de bat buoc: don khuya, ghe doc duong, hanh ly, xe 7 cho, dat xe truoc, thanh toan, hoa don, huy/chuyen lich.\nOutput chi JSON array.\nKhong lap lai cau tra loi giong nhau giua cac FAQ.",
			'meta_title' => 'Taxi {{route.from}} di {{route.to}} gia tu {{route.formatted_price}}',
			'meta_description' => 'Dat taxi {{route.from}} di {{route.to}}, gia tu {{route.formatted_price}}, don tan noi 24/7.',
			'image_prompt' => 'Anh minh hoa xe taxi chay tren tuyen {{route.from}} di {{route.to}}, phong cach thuc te, mien Tay Viet Nam, sang ro, chuyen nghiep.',
			'review_snippet' => 'Viet 3 review ngan ve dich vu taxi {{route.from}} di {{route.to}}.',
			'price_guide' => "Viet bai huong dan gia taxi tuyen {{route.from}} di {{route.to}} theo intent {{seo.search_intent}}. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'travel_guide' => "Viet bai travel guide lien quan hanh trinh {{route.from}} di {{route.to}}, giu giong van thuc te dia phuong. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'food_guide' => "Viet bai food guide ket hop hanh trinh {{route.from}} di {{route.to}}, uu tien dac san dia phuong khong bia du lieu. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'destination_guide' => "Viet bai gioi thieu diem den cho tuyen {{route.from}} di {{route.to}}. Neu co dia danh, khu du lich, benh vien, san bay, hay tieng local thi dung thuc te. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'hospital_route' => "Viet bai tuyen taxi di benh vien cho {{route.from}} di {{route.to}}. Uu tien nhu cau di gap, di som, di nguoi lon tuoi. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'airport_route' => "Viet bai tuyen taxi di san bay cho {{route.from}} di {{route.to}}, nhan manh gio giac, hanh ly va dat xe truoc. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'business_trip' => "Viet bai taxi di cong tac cho tuyen {{route.from}} di {{route.to}}, giu giong van thuc te va toan tinh hanh trinh. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'family_trip' => "Viet bai taxi cho gia dinh di tuyen {{route.from}} di {{route.to}}, nhan manh xe rong, an toan, di voi tre nho nguoi lon tuoi. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'wedding_event' => "Viet bai taxi di dam cuoi / su kien cho tuyen {{route.from}} di {{route.to}}, giu van phong ro rang, de dat xe doan. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'pilgrimage' => "Viet bai taxi di chua / hanh huong cho tuyen {{route.from}} di {{route.to}}, nhan manh di som, ghe diem, hanh trinh thoai mai. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'weekend_itinerary' => "Viet bai lich trinh cuoi tuan cho tuyen {{route.from}} di {{route.to}}, ket hop di chuyen, tham quan va an uong. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'budget_tips' => "Viet bai meo tiet kiem chi phi taxi cho tuyen {{route.from}} di {{route.to}}, khong be bong gia va khong spam keyword. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'local_experience' => "Viet bai kinh nghiem nguoi dia phuong ve tuyen {{route.from}} di {{route.to}}. Giong nguoi trong nghe, thuc te, co local context. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'comparison' => "Viet bai so sanh taxi rieng, xe khach, xe hop dong cho tuyen {{route.from}} di {{route.to}}. Can bang, thuc te, co CTA mem. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'faq_article' => "Viet bai hoi dap chuyen sau cho tuyen {{route.from}} di {{route.to}} voi 8-12 FAQ thuc te. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'seasonal_content' => "Viet bai theo mua / le / Tet cho tuyen {{route.from}} di {{route.to}}, co canh bao dat xe som va thoi diem di chuyen. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
			'route_cluster' => "Viet bai tong hop cum tuyen lien quan quanh {{route.from}} di {{route.to}}, co internal links va topical authority. Muc tieu {{content.min_words}}-{{content.max_words}} tu. Bat buoc output JSON schema nhu route_landing.",
		];
	}

	public static function get(): array {
		$templates = get_option( self::OPTION, [] );
		$templates = wp_parse_args( is_array( $templates ) ? $templates : [], self::defaults() );
		$defaults  = self::defaults();

		$route_landing = (string) ( $templates['route_landing'] ?? '' );
		$route_faq     = (string) ( $templates['route_faq'] ?? '' );

		// Auto-upgrade old minimal templates to richer defaults.
		if ( '' === trim( $route_landing ) || strlen( $route_landing ) < 600 || false !== stripos( $route_landing, 'Viet landing page taxi' ) ) {
			$templates['route_landing'] = $defaults['route_landing'];
		}
		if ( '' === trim( $route_faq ) || strlen( $route_faq ) < 120 || false !== stripos( $route_faq, 'Tao 5 FAQ' ) ) {
			$templates['route_faq'] = $defaults['route_faq'];
		}

		foreach ( $templates as $key => $template ) {
			if ( ! is_string( $template ) ) {
				continue;
			}
			$templates[ $key ] = self::ensure_vietnamese_diacritics( $template );
		}

		return $templates;
	}

	public static function save( array $templates ): void {
		$clean = [];
		foreach ( self::defaults() as $key => $default ) {
			$clean[ $key ] = sanitize_textarea_field( (string) ( $templates[ $key ] ?? $default ) );
		}
		update_option( self::OPTION, $clean, false );
		update_option( self::VERSION_OPTION, SRT_VERSION, false );
	}

	public static function reset(): void {
		update_option( self::OPTION, self::defaults(), false );
		update_option( self::VERSION_OPTION, SRT_VERSION, false );
	}

	private static function ensure_vietnamese_diacritics( string $template ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return $template;
		}
		$prefix = "BẮT BUỘC: Toàn bộ nội dung phải viết bằng tiếng Việt có dấu chuẩn Unicode UTF-8. Không được trả về tiếng Việt không dấu, không romanize. Nếu phát hiện đoạn không dấu trong title, heading, meta, paragraph hoặc FAQ thì phải tự viết lại trước khi trả kết quả.\nBẮT BUỘC: Không lặp paragraph, không lặp ý giữa các section, không kéo dài bằng filler.\n\n";
		if ( false !== stripos( $template, 'Toàn bộ nội dung phải viết bằng tiếng Việt có dấu' ) ) {
			return $template;
		}
		return $prefix . $template;
	}
}
