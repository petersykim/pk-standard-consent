<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$opts  = get_option( 'pk_standard_consent' );
$clean = is_array( $opts ) && ! empty( $opts['uninstall_clean'] );

if ( $clean ) {
    require_once __DIR__ . '/vendor/autoload.php';
    delete_option( 'pk_standard_consent' );
    delete_option( 'pk_sc_db_version' );
    \PK\StandardConsent\Schema::dropTable();
    \PK\StandardConsent\Schema::dropScanTable();
    delete_transient( 'pk_sc_scan_status' );
    delete_option( 'pk_standard_consent_regions' );
    delete_option( 'pk_standard_consent_categories' );
    delete_option( 'pk_standard_consent_country_group' );
    delete_option( 'pk_sc_activation_notice' );
    delete_transient( 'pk_sc_log_cleanup_last' );
    wp_clear_scheduled_hook( 'pk_sc_log_cleanup' );
}
