<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AdminAuth
{
	public function verifyAdminNonce( \WP_REST_Request $request ): bool
	{
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( null === $nonce ) {
			$raw_nonce = $request->get_param( '_wpnonce' );
			$nonce     = is_string( $raw_nonce ) ? $raw_nonce : '';
		}
		if ( '' === $nonce ) {
			return false;
		}
		return false !== wp_verify_nonce( $nonce, 'wp_rest' ) && current_user_can( 'manage_options' );
	}
}
