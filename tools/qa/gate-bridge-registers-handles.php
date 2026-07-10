<?php
/**
 * Rule A guard for C1 (run: ddev exec wp eval-file <this> --path=wordpress --allow-root).
 * Exits nonzero on fail. Self-seeds and restores by exact identity.
 *
 * THE #1 fix: the admin Categories & Scripts screen saves category->handle into the
 * pk_standard_consent_categories option, but nothing fed those handles to the gate, so an
 * admin-configured Reject blocked nothing. GateBridge::registerConfiguredScripts now reads
 * that option and registers each handle into ScriptRegistry before blockUnconsented runs.
 *
 * This asserts: (a) a handle assigned to 'analytics' in the option is registered into the
 * ScriptRegistry by the bridge, (b) a handle assigned to 'necessary' is NOT (never gateable).
 * (No declare(strict_types) — eval-file uses eval().)
 */

use PK\StandardConsent\GateBridge;
use PK\StandardConsent\ScriptRegistry;
use PK\StandardConsent\Category;

$GUARD = 'pk-sc-guard-c1-analytics';
$GUARD_NEC = 'pk-sc-guard-c1-necessary';

// --- snapshot for restore ---
$prev_cats = get_option( 'pk_standard_consent_categories', null );

// --- seed: assign our unique handle to analytics + one to necessary ---
update_option(
    'pk_standard_consent_categories',
    [
        'analytics'  => [ 'description' => '', 'scripts' => [ $GUARD ] ],
        'necessary'  => [ 'description' => '', 'scripts' => [ $GUARD_NEC ] ],
    ]
);

// Fresh registry instance via reflection so prior state in this process can't poison it.
$ref = new ReflectionClass( ScriptRegistry::class );
$prop = $ref->getProperty( 'instance' );
$prop->setAccessible( true );
$prop->setValue( null, null );

( new GateBridge() )->registerConfiguredScripts();

$registered = ScriptRegistry::instance()->all();

$ok = [];
// (a) analytics handle made it into the registry, with the right category.
$ok['analytics_registered'] = isset( $registered[ $GUARD ] )
    && $registered[ $GUARD ]['category'] === Category::Analytics;
// (b) necessary handle was skipped (necessary is never gateable).
$ok['necessary_skipped'] = ! isset( $registered[ $GUARD_NEC ] );

// --- restore ---
if ( null === $prev_cats ) {
    delete_option( 'pk_standard_consent_categories' );
} else {
    update_option( 'pk_standard_consent_categories', $prev_cats );
}
$prop->setValue( null, null );

if ( ! in_array( false, $ok, true ) ) {
    echo "PASS: GateBridge registers admin-assigned analytics handle; skips necessary\n";
    exit( 0 );
}
fwrite( STDERR, 'FAIL: ' . json_encode( $ok ) . "\n" );
exit( 1 );
