<?php
// Round-5 audit F3 guard: per-visitor erasure must delete ONLY the target visitor's rows.
//
// Seeds 3 throwaway rows (2 sharing visitor hash A, 1 with hash B), DRIVES the real
// AdminRest::handleVisitorErase by row id, asserts: response deleted=2, hash-A rows gone,
// hash-B row intact, no other row touched. Also asserts 404 for an unknown id. Tears its
// fixtures down by exact id.
//
// Run: wp eval-file tools/qa/consent-erase-scoped.php
// NOTE: no declare(strict_types) — wp eval-file forbids it.

use PK\StandardConsent\AdminRest;

$fail = function ( $msg ) {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
};

global $wpdb;
$table    = $wpdb->prefix . 'pk_sc_consent_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$baseline = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

$hash_a = str_repeat( 'a', 63 ) . '1';
$hash_b = str_repeat( 'b', 63 ) . '1';
$ids    = [];
foreach ( [ $hash_a, $hash_a, $hash_b ] as $h ) {
	$wpdb->insert(
		$table,
		[
			'visitor_hash' => $h,
			'ua_hash'      => 'qa-guard',
			'region'       => 'QA-ER',
			'policy_ver'   => 'qa',
		],
		[ '%s', '%s', '%s', '%s' ]
	);
	$ids[] = (int) $wpdb->insert_id;
}

$teardown = function () use ( $wpdb, $table, $ids ) {
	foreach ( $ids as $id ) {
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}
};

$rest = new AdminRest();
$req  = new WP_REST_Request( 'DELETE', '/pk-standard-consent/v1/admin/log/visitor' );
$req->set_param( 'id', $ids[0] );
$res = $rest->handleVisitorErase( $req );

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$a_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visitor_hash = %s", $hash_a ) );
$b_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visitor_hash = %s", $hash_b ) );
$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$missing_req = new WP_REST_Request( 'DELETE', '/pk-standard-consent/v1/admin/log/visitor' );
$missing_req->set_param( 'id', 999999999 );
$missing_status = $rest->handleVisitorErase( $missing_req )->get_status();

$teardown();

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$final = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

if ( 200 !== $res->get_status() || 2 !== (int) ( $res->get_data()['deleted'] ?? -1 ) ) {
	$fail( 'erase response wrong: status=' . $res->get_status() . ' body=' . wp_json_encode( $res->get_data() ) );
}
if ( 0 !== $a_left ) {
	$fail( "hash-A rows survived the erase ({$a_left} left)" );
}
if ( 1 !== $b_left ) {
	$fail( "hash-B row count wrong after erase ({$b_left}, expected 1 — over-delete!)" );
}
if ( $total !== $baseline + 1 ) {
	$fail( "unrelated rows touched: total {$total}, expected baseline+1 " . ( $baseline + 1 ) );
}
if ( 404 !== $missing_status ) {
	$fail( "unknown id returned {$missing_status}, expected 404" );
}
if ( $final !== $baseline ) {
	$fail( "teardown incomplete ({$baseline} -> {$final})" );
}

echo "PASS: erase deleted exactly the target visitor's 2 rows; sibling + baseline untouched; unknown id 404\n";
exit( 0 );
