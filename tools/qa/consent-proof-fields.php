<?php
/**
 * Rule A guard for C5 (D-consent-proof). Run via wp eval-file. Exits nonzero on fail.
 * Self-seeds a uniquely-marked log row and cleans it up by exact identity (region marker).
 * (No declare(strict_types) — eval-file uses eval().)
 *
 * Asserts: the consent_log table carries consent_method + policy_hash columns (migration ran),
 * and a row written through ConsentLog::insert() persists the method and the presented-policy hash.
 */

use PK\StandardConsent\Schema;
use PK\StandardConsent\ConsentLog;

global $wpdb;
$table  = $wpdb->prefix . 'pk_sc_consent_log';
$marker = 'QAC5GRD';

Schema::createTable();

$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore

$ok = [];
$ok['col_method'] = in_array( 'consent_method', $cols, true );
$ok['col_hash']   = in_array( 'policy_hash', $cols, true );

ConsentLog::insert(
    [ 'preferences' => true, 'analytics' => false, 'marketing' => false ],
    $marker,
    '1.0',
    'reject_all',
    str_repeat( 'a', 64 )
);

$row = $wpdb->get_row(
    $wpdb->prepare( "SELECT consent_method, policy_hash FROM {$table} WHERE region=%s ORDER BY id DESC LIMIT 1", $marker ),
    ARRAY_A
);
$ok['row_method'] = is_array( $row ) && 'reject_all' === ( $row['consent_method'] ?? '' );
$ok['row_hash']   = is_array( $row ) && str_repeat( 'a', 64 ) === ( $row['policy_hash'] ?? '' );

// cleanup by exact identity
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE region=%s", $marker ) ); // phpcs:ignore

if ( ! in_array( false, $ok, true ) ) {
    echo "PASS: consent_method + policy_hash columns present and persisted on the log row\n";
    exit( 0 );
}
fwrite( STDERR, 'FAIL: ' . json_encode( $ok ) . "\n" );
exit( 1 );
