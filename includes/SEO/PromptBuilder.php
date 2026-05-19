<?php
/**
 * Taxi SEO prompt builder.
 *
 * @package SimilarRouteTrip\SEO
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\SEO;

defined( 'ABSPATH' ) || exit;

final class PromptBuilder {

	public static function route_article_prompt( array $route ): string {
		$from     = (string) ( $route['from_city'] ?? '' );
		$to       = (string) ( $route['to_city'] ?? '' );
		$distance = (string) ( $route['distance_km'] ?? '' );
		$duration = (string) ( $route['duration_min'] ?? '' );
		$price    = (string) ( $route['price_display'] ?: ( $route['price_min'] ?? '' ) );

		return sprintf(
			"Viet bai SEO chuyen sau cho dich vu taxi %s di %s.\nMuc tieu: khach co nhu cau dat xe, can gia minh bach, lo trinh ro, CTA manh.\nDu lieu bat buoc:\n- Diem di: %s\n- Diem den: %s\n- Khoang cach: %s km\n- Thoi gian: %s phut\n- Gia tu: %s\nYeu cau noi dung:\n- Gioi thieu tu nhien, khong spam keyword.\n- Co bang gia tom tat theo loai xe neu co du lieu.\n- Neu co phu phi thi noi ro la gia tham khao va can xac nhan theo lo trinh thuc te.\n- Them FAQ ve gia, thoi gian don, hinh thuc thanh toan, dat xe 24/7.\n- CTA dat xe qua hotline/Zalo/form dat xe.\n- Van phong tieng Viet, than thien, chuyen nghiep, phu hop dich vu taxi mien Tay.",
			$from,
			$to,
			$from,
			$to,
			$distance,
			$duration,
			$price
		);
	}

	public static function meta_prompt( array $route ): string {
		return sprintf(
			'Viet meta title duoi 60 ky tu va meta description duoi 155 ky tu cho tuyen taxi %s di %s, gia tu %s, co CTA dat xe.',
			(string) ( $route['from_city'] ?? '' ),
			(string) ( $route['to_city'] ?? '' ),
			(string) ( $route['price_display'] ?? '' )
		);
	}
}
