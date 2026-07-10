<?php
// No strict_types: wp eval-file evals this file, where the declare would fatal.

/**
 * Guard for the script-handle save path (round-3 F3 blank-drop + round-4 F1 object shape).
 *
 * Exercises the REAL AdminRest::handleCategoriesPut with the SAME payload the admin UI
 * sends — scripts as {handle, src, type} OBJECTS — and asserts: a real handle PERSISTS,
 * a blank handle is DROPPED. The round-3 guard only grepped source + read the option, so
 * it missed F1 (the filter blanked every object-shaped handle). This one drives the handler.
 *
 * Safe: snapshots the live option, restores it in a finally-style teardown. No permanent write.
 *
 * Run: wp eval-file tools/qa/consent-bug-blank-handles.php
 */

$option = 'pk_standard_consent_categories';
$before = get_option( $option );

$ok   = true;
$msg  = '';

try {
	$req = new WP_REST_Request( 'PUT', '/pk-standard-consent/v1/categories' );
	$req->set_body_params( [] );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( [
		'categories' => [
			[
				'slug'    => 'analytics',
				'scripts' => [
					[ 'handle' => 'ga4-real-handle', 'src' => '', 'type' => 'enqueued' ], // object shape (UI)
					[ 'handle' => '', 'src' => '', 'type' => 'enqueued' ],                 // blank object → drop
					'legacy-string-handle',                                                // bare string (legacy)
					'',                                                                    // blank string → drop
				],
			],
		],
	] ) );

	( new \PK\StandardConsent\AdminRest() )->handleCategoriesPut( $req );

	$stored  = get_option( $option );
	$scripts = $stored['analytics']['scripts'] ?? [];

	if ( ! in_array( 'ga4-real-handle', $scripts, true ) ) {
		$ok = false; $msg = 'FAIL: object-shaped handle ga4-real-handle was NOT persisted (round-4 F1 regression)';
	} elseif ( ! in_array( 'legacy-string-handle', $scripts, true ) ) {
		$ok = false; $msg = 'FAIL: bare-string handle was NOT persisted';
	} elseif ( in_array( '', $scripts, true ) || count( $scripts ) !== 2 ) {
		$ok = false; $msg = 'FAIL: blank handle leaked or unexpected count (' . wp_json_encode( $scripts ) . ')';
	}
} catch ( \Throwable $e ) {
	$ok = false; $msg = 'FAIL: handler threw — ' . $e->getMessage();
}

// Restore the live option exactly.
if ( false === $before ) {
	delete_option( $option );
} else {
	update_option( $option, $before );
}

if ( $ok ) {
	echo "PASS: object + string handles persist, blanks dropped, live option restored\n";
	exit( 0 );
}
echo $msg . "\n";
exit( 1 );
