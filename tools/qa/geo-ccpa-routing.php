<?php
/**
 * Rule A guard for C3 (US/CCPA reachable) + C4 (real IP-geo provider fetch).
 * Run: ddev exec wp eval-file <this> --path=wordpress --allow-root. Exits nonzero on fail.
 * Self-seeds the $_SERVER headers + a provider URL; restores the consent option + headers.
 * (No declare(strict_types) — eval-file uses eval().)
 *
 * Asserts:
 *  C3a  US country routes to the CA (CCPA) group with an opt-out model, no manual config.
 *  C3b  a California visitor (geo header US + state header CA) detects as 'US-CA' -> CA opt-out.
 *  C3c  a non-CA US state (TX) still reaches CCPA opt-out (falls back to the US country group).
 *  C3d  an admin country->group override re-routes a country (here: FR -> ROW).
 *  C4   a headerless visitor with a provider URL set resolves a country from the IP lookup.
 */

use PK\StandardConsent\Geo;
use PK\StandardConsent\RegionRules;

$ok = [];

// --- snapshot ---
$prev_opt       = get_option( 'pk_standard_consent', [] );
$prev_cg        = get_option( 'pk_standard_consent_country_group', null );
$prev_country   = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
$prev_region    = $_SERVER['HTTP_CF_REGION_CODE'] ?? null;
$prev_remote    = $_SERVER['REMOTE_ADDR'] ?? null;

// C3a: US -> CA opt-out, no config.
$ok['c3a_us_ccpa'] = 'CA' === RegionRules::resolveGroup( 'US' )
    && 'opt-out' === RegionRules::resolveDefaults( RegionRules::resolveGroup( 'US' ) )['model'];

// C3b: California detected from headers.
$_SERVER['HTTP_CF_IPCOUNTRY']   = 'US';
$_SERVER['HTTP_CF_REGION_CODE'] = 'CA';
$ca = Geo::detect();
$ok['c3b_ca_detect'] = 'US-CA' === $ca && 'opt-out' === RegionRules::resolveDefaults( RegionRules::resolveGroup( $ca ) )['model'];

// C3c: Texas still CCPA opt-out.
$_SERVER['HTTP_CF_REGION_CODE'] = 'TX';
$tx = Geo::detect();
$ok['c3c_tx_ccpa'] = 'opt-out' === RegionRules::resolveDefaults( RegionRules::resolveGroup( $tx ) )['model'];

// C3d: admin override re-routes a country.
update_option( 'pk_standard_consent_country_group', [ 'FR' => 'ROW' ] );
$ok['c3d_override'] = 'ROW' === RegionRules::resolveGroup( 'FR' );
if ( null === $prev_cg ) {
    delete_option( 'pk_standard_consent_country_group' );
} else {
    update_option( 'pk_standard_consent_country_group', $prev_cg );
}

// C4: headerless visitor + provider URL -> region from IP lookup.
unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CF_REGION_CODE'] );
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
$cfg = is_array( $prev_opt ) ? $prev_opt : [];
$cfg['geo_provider_url'] = 'https://ipapi.co/{ip}/json/';
update_option( 'pk_standard_consent', $cfg );
$provider_region = Geo::detect();
$ok['c4_provider'] = 'US' === $provider_region;

// --- restore ---
update_option( 'pk_standard_consent', $prev_opt );
if ( null === $prev_country ) { unset( $_SERVER['HTTP_CF_IPCOUNTRY'] ); } else { $_SERVER['HTTP_CF_IPCOUNTRY'] = $prev_country; }
if ( null === $prev_region )  { unset( $_SERVER['HTTP_CF_REGION_CODE'] ); } else { $_SERVER['HTTP_CF_REGION_CODE'] = $prev_region; }
if ( null === $prev_remote )  { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $prev_remote; }

if ( ! in_array( false, $ok, true ) ) {
    echo "PASS: US->CCPA opt-out, CA state detection, US-state fallback, country override, IP-provider fetch\n";
    exit( 0 );
}
fwrite( STDERR, 'FAIL: ' . json_encode( $ok ) . "\n" );
exit( 1 );
