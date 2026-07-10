<?php
/**
 * Rule A guard for C7 (default posture). Run via wp eval-file. Exits nonzero on fail.
 * Snapshots and restores the live consent option exactly, so a real install's settings
 * are never disturbed. (No declare(strict_types) — eval-file uses eval().)
 *
 * Asserts: a fresh activation (no prior consent option) turns the gate ON and raises the
 * one-time admin notice, and the Config default for consent_enabled is true.
 */

use PK\StandardConsent\Plugin;
use PK\StandardConsent\Config;

$prev          = get_option( 'pk_standard_consent', false );
$prev_notice   = get_option( 'pk_sc_activation_notice', false );

delete_option( 'pk_standard_consent' );
delete_option( 'pk_sc_activation_notice' );

Plugin::onActivate();

$opt = get_option( 'pk_standard_consent', false );
$ok  = [];
$ok['enabled_on_fresh_activate'] = is_array( $opt ) && ! empty( $opt['consent_enabled'] );
$ok['notice_raised']             = '1' === (string) get_option( 'pk_sc_activation_notice', '' );
$ok['config_default_on']         = true === (bool) Config::get( 'consent_enabled' );

// restore exact prior state
if ( false === $prev ) {
    delete_option( 'pk_standard_consent' );
} else {
    update_option( 'pk_standard_consent', $prev );
}
if ( false === $prev_notice ) {
    delete_option( 'pk_sc_activation_notice' );
} else {
    update_option( 'pk_sc_activation_notice', $prev_notice );
}

if ( ! in_array( false, $ok, true ) ) {
    echo "PASS: fresh activation enables the gate + raises the prompt; config default is ON\n";
    exit( 0 );
}
fwrite( STDERR, 'FAIL: ' . json_encode( $ok ) . "\n" );
exit( 1 );
