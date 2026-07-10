<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Schema
{
    public static function createTable(): void
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'pk_sc_consent_log';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // consent_method + policy_hash (D-consent-proof): GDPR Art. 7(1) "demonstrate consent".
        // policy_hash is a SHA-256 of the exact policy text/version/url the visitor was shown;
        // dbDelta adds these columns to an existing table on upgrade (no data loss).
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_hash varchar(64) NOT NULL,
            ua_hash varchar(64) NOT NULL,
            region varchar(8) NOT NULL DEFAULT '',
            necessary tinyint(1) NOT NULL DEFAULT 1,
            preferences tinyint(1) NOT NULL DEFAULT 0,
            analytics tinyint(1) NOT NULL DEFAULT 0,
            marketing tinyint(1) NOT NULL DEFAULT 0,
            policy_ver varchar(20) NOT NULL DEFAULT '',
            consent_method varchar(20) NOT NULL DEFAULT '',
            policy_hash varchar(64) NOT NULL DEFAULT '',
            consented_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY visitor_hash (visitor_hash),
            KEY consented_at (consented_at),
            KEY region (region),
            KEY policy_ver (policy_ver)
        ) {$charset};";

        dbDelta( $sql );
    }

    public static function dropTable(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pk_sc_consent_log" );
    }

    public static function createScanTable(): void
    {
        global $wpdb;

        $table   = $wpdb->prefix . 'pk_sc_scan_results';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cookie_name varchar(128) NOT NULL,
            provider varchar(64) NOT NULL DEFAULT '',
            category varchar(20) NOT NULL DEFAULT 'necessary',
            category_override varchar(20) DEFAULT NULL,
            duration varchar(64) NOT NULL DEFAULT '',
            purpose varchar(255) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT 'signature',
            compliance_flag tinyint(1) NOT NULL DEFAULT 0,
            first_seen_url varchar(2083) NOT NULL DEFAULT '',
            scanned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cookie_name (cookie_name),
            KEY category (category),
            KEY source (source)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function dropScanTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pk_sc_scan_results';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is a fixed string built from $wpdb->prefix, never user input.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}
