<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIKeyVault {
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . ( false === $cipher ? '' : $cipher ) );
	}

	public static function decrypt( string $encrypted ): string {
		if ( '' === $encrypted ) {
			return '';
		}
		$raw = base64_decode( $encrypted, true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	public static function mask( string $plain ): string {
		if ( strlen( $plain ) <= 8 ) {
			return '' === $plain ? '' : '****';
		}
		return substr( $plain, 0, 4 ) . '...' . substr( $plain, -4 );
	}
}
