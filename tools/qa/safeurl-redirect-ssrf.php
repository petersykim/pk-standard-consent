<?php
// Round-6 audit F1 guard (S1 SSRF): SafeUrl::get must re-validate every redirect hop. A URL
// that itself passes isUnsafe() but 3xx-redirects to an internal/metadata IP must NOT be
// followed — a bare wp_remote_get (redirection=>5) followed it unchecked.
//
// DRIVES the real SafeUrl::get with the network stubbed via pre_http_request: the first URL
// (a safe external host) returns a 302 to an internal IP; the guard asserts SafeUrl::get
// returns a WP_Error (refused) and never returns the internal target's body. Also asserts a
// safe non-redirecting fetch still succeeds, and a direct internal URL is refused.
//
// Run: wp eval-file tools/qa/safeurl-redirect-ssrf.php
// NOTE: no declare(strict_types) — wp eval-file forbids it.

use PK\StandardConsent\SafeUrl;

$fail = function ( $msg ) {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
};

$external_redirector = 'https://example.com/redirect-to-internal';
$internal_target     = 'http://169.254.169.254/latest/meta-data/';
$safe_plain          = 'https://example.com/ok';
$secret              = 'PK-SSRF-METADATA-SECRET';

$stub = static function ( $pre, $args, $url ) use ( $external_redirector, $internal_target, $safe_plain, $secret ) {
	if ( $url === $external_redirector ) {
		return [
			'headers'  => [ 'location' => $internal_target ],
			'body'     => '',
			'response' => [ 'code' => 302, 'message' => 'Found' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
	if ( $url === $internal_target ) {
		// If this is ever reached, the SSRF guard failed — return a marker body.
		return [
			'headers'  => [],
			'body'     => $secret,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
	if ( $url === $safe_plain ) {
		return [
			'headers'  => [ 'content-type' => 'text/html' ],
			'body'     => 'SAFE-OK',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
	return $pre;
};
add_filter( 'pre_http_request', $stub, 10, 3 );

// 1. The redirect-to-internal case: must be refused, never returns the internal body.
$r1 = SafeUrl::get( $external_redirector, [ 'timeout' => 3 ] );
$r1_body = is_wp_error( $r1 ) ? '' : (string) wp_remote_retrieve_body( $r1 );

// 2. A safe non-redirecting fetch must still work.
$r2 = SafeUrl::get( $safe_plain, [ 'timeout' => 3 ] );
$r2_body = is_wp_error( $r2 ) ? '' : (string) wp_remote_retrieve_body( $r2 );

// 3. A direct internal URL must be refused up front.
$r3 = SafeUrl::get( $internal_target, [ 'timeout' => 3 ] );

remove_filter( 'pre_http_request', $stub, 10 );

if ( strpos( $r1_body, $secret ) !== false ) {
	$fail( 'SSRF: a redirect to an internal metadata IP was followed and its body returned' );
}
if ( ! is_wp_error( $r1 ) ) {
	$fail( 'redirect-to-internal did not return a WP_Error (should be refused): body=' . $r1_body );
}
if ( $r2_body !== 'SAFE-OK' ) {
	$fail( 'a safe non-redirecting fetch failed after the hardening: ' . var_export( $r2_body, true ) );
}
if ( ! is_wp_error( $r3 ) ) {
	$fail( 'a direct internal URL was not refused up front' );
}

echo "PASS: redirect-to-internal refused (no body leak); direct internal refused; safe fetch still works\n";
exit( 0 );
