<?php
// Round-5 audit F1 guard: POST /consent must rate-limit per visitor IP — the /config nonce
// is public, so without a throttle any visitor can flood the consent log unboundedly.
//
// DRIVES the real Rest::postConsent with a deliberately WRONG policy version, so no row is
// ever inserted (the version check rejects with 409 after the rate check) — the guard
// exercises the real throttle path with zero live-data writes. Expects 409 for the first
// 10 calls, 429 from the 11th. Cleans up its own transient.
//
// Run: wp eval-file tools/qa/consent-bug-log-flood.php
// NOTE: no declare(strict_types) — wp eval-file forbids it.

use PK\StandardConsent\Rest;

$fail = function ( $msg ) {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
};

global $wpdb;
$table    = $wpdb->prefix . 'pk_sc_consent_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$baseline = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

// TEST-NET-3 address (RFC 5737) — never a real visitor.
$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
$_SERVER['HTTP_USER_AGENT'] = 'pk-sc-qa-flood-guard';

$rl_key = 'pk_sc_rl_' . hash_hmac( 'sha256', '203.0.113.77', wp_salt( 'auth' ) );
delete_transient( $rl_key );

$rest = new Rest();
$call = function () use ( $rest ) {
	$req = new WP_REST_Request( 'POST', '/pk-standard-consent/v1/consent' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( (string) wp_json_encode( [
		'categories' => [ 'analytics' => true ],
		'region'     => 'QA',
		'version'    => 'QA-GUARD-WRONG-VERSION',
		'method'     => 'custom',
	] ) );
	return $rest->postConsent( $req )->get_status();
};

for ( $i = 1; $i <= 10; $i++ ) {
	$status = $call();
	if ( 429 === $status ) {
		delete_transient( $rl_key );
		$fail( "call {$i} already throttled (limit too tight for a real visitor)" );
	}
	if ( 409 !== $status ) {
		delete_transient( $rl_key );
		$fail( "call {$i} returned {$status}, expected 409 version_mismatch" );
	}
}

$status = $call();
delete_transient( $rl_key );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
if ( $after !== $baseline ) {
	$fail( "guard leaked rows into the consent log ({$baseline} -> {$after})" );
}

if ( 429 !== $status ) {
	$fail( "call 11 returned {$status}, expected 429 — POST /consent is not rate-limited (log flood possible)" );
}

echo "PASS: 10 calls allowed, 11th throttled 429; zero rows written\n";
exit( 0 );
