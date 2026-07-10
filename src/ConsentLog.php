<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ConsentLog
{
    /** @param array<string, bool> $grants */
    public static function insert( array $grants, string $region, string $policy_ver, string $method = '', string $policy_hash = '' ): void
    {
        global $wpdb;

        // Geo::clientIp() prefers CF-Connecting-IP over REMOTE_ADDR (the CF edge IP) so the
        // per-visitor hash stays distinct behind Cloudflare, keeping the audit log meaningful.
        $ip = Geo::clientIp();
        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

        $visitor_hash = hash_hmac( 'sha256', $ip . $ua . gmdate( 'Y-m-d' ), wp_salt( 'auth' ) );
        $ua_hash      = hash_hmac( 'sha256', $ua, wp_salt( 'auth' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $wpdb->prefix . 'pk_sc_consent_log',
            [
                'visitor_hash'   => $visitor_hash,
                'ua_hash'        => $ua_hash,
                'region'         => $region,
                'necessary'      => 1,
                'preferences'    => (int) $grants['preferences'],
                'analytics'      => (int) $grants['analytics'],
                'marketing'      => (int) $grants['marketing'],
                // policy_ver is varchar(20); truncate so an over-long version still records
                // the audit row (trimmed) instead of failing the INSERT and silently losing
                // the GDPR Art. 7(1) consent record while the visitor sees {"ok":true}.
                'policy_ver'     => mb_substr( $policy_ver, 0, 20 ),
                'consent_method' => $method,
                'policy_hash'    => $policy_hash,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $result ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped
            trigger_error( 'pk_sc: consent log insert failed: ' . $wpdb->last_error, E_USER_WARNING );
        }

        self::trimIfNeeded();
    }

    private static function trimIfNeeded(): void
    {
        if ( false !== get_transient( 'pk_sc_log_cleanup_last' ) ) {
            return;
        }

        global $wpdb;

        $days  = max( 1, (int) Config::get( 'log_retention_days' ) );
        $table = $wpdb->prefix . 'pk_sc_consent_log';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE consented_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        set_transient( 'pk_sc_log_cleanup_last', 1, DAY_IN_SECONDS );
    }
}
