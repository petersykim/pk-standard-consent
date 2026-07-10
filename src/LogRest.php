<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LogRest
{
    use AdminAuth;

    private const NS               = 'pk-standard-consent/v1';
    private const PER_PAGE_DEFAULT = 50;
    private const PER_PAGE_MAX     = 200;
    private const EXPORT_CHUNK     = 500;

    public function register(): void
    {
        register_rest_route(
            self::NS,
            '/admin/log',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleLog' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
            ]
        );

        register_rest_route(
            self::NS,
            '/admin/log/export',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleExport' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
            ]
        );

        register_rest_route(
            self::NS,
            '/admin/regions',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleRegionsGet' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
            ]
        );

        register_rest_route(
            self::NS,
            '/admin/regions',
            [
				'methods'             => 'PUT',
				'callback'            => [ $this, 'handleRegionsPut' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
            ]
        );

        register_rest_route(
            self::NS,
            '/admin/log/stats',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleLogStats' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
            ]
        );
    }

    public function handleLog( \WP_REST_Request $request ): \WP_REST_Response
    {
        global $wpdb;

        $raw_page = $request->get_param( 'page' );
        $raw_pp   = $request->get_param( 'per_page' );
        $page     = max( 1, is_numeric( $raw_page ) ? (int) $raw_page : 1 );
        $per_page = is_numeric( $raw_pp ) ? (int) $raw_pp : 0;
        if ( $per_page <= 0 ) {
            $per_page = self::PER_PAGE_DEFAULT;
        }
        $per_page = min( $per_page, self::PER_PAGE_MAX );
        $offset   = ( $page - 1 ) * $per_page;

        $table = $wpdb->prefix . 'pk_sc_consent_log';

        // Optional region / policy-version filters (the consent-log search input). Each adds a
        // prepared WHERE clause; a blank filter is ignored. Built as ($sql, $args) so COUNT and
        // SELECT share the same predicate.
        [ $where, $where_args ] = $this->buildLogFilter( $request );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
        $total     = (int) ( [] === $where_args
            ? $wpdb->get_var( $count_sql )
            : $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) ) );

        $raw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, visitor_hash, ua_hash, region, necessary, preferences, analytics, marketing, policy_ver, consent_method, policy_hash, consented_at FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge( $where_args, [ $per_page, $offset ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $rows = array_map( [ $this, 'maskRow' ], is_array( $raw ) ? $raw : [] );

        return new \WP_REST_Response(
            [
				'rows'  => $rows,
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
            ]
        );
    }

    /**
     * Builds the optional WHERE clause + bound args for the region / version filters.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function buildLogFilter( \WP_REST_Request $request ): array
    {
        $clauses = [];
        $args    = [];

        $region_raw = $request->get_param( 'region' );
        $region     = sanitize_text_field( is_scalar( $region_raw ) ? (string) $region_raw : '' );
        if ( '' !== $region ) {
            $clauses[] = 'region = %s';
            $args[]    = substr( $region, 0, 8 );
        }

        $version_raw = $request->get_param( 'version' );
        $version     = sanitize_text_field( is_scalar( $version_raw ) ? (string) $version_raw : '' );
        if ( '' !== $version ) {
            $clauses[] = 'policy_ver = %s';
            $args[]    = $version;
        }

        $where = [] === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );
        return [ $where, $args ];
    }

    public function handleExport( \WP_REST_Request $request ): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
    {
        global $wpdb;

        $table = $wpdb->prefix . 'pk_sc_consent_log';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        if ( false === $out ) {
            exit;
        }

        fputcsv( $out, [ 'id', 'visitor_hash', 'ua_hash', 'region', 'necessary', 'preferences', 'analytics', 'marketing', 'policy_ver', 'consent_method', 'policy_hash', 'consented_at' ] );

        $offset  = 0;
        $fetched = 0;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, visitor_hash, ua_hash, region, necessary, preferences, analytics, marketing, policy_ver, consent_method, policy_hash, consented_at FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                    self::EXPORT_CHUNK,
                    $offset
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if ( ! is_array( $batch ) || [] === $batch ) {
                break;
            }

            foreach ( $batch as $row ) {
                fputcsv( $out, $this->maskRow( $row ) );
            }

            $offset += self::EXPORT_CHUNK;
            $fetched = count( $batch );
        } while ( self::EXPORT_CHUNK === $fetched );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output is not a WP_Filesystem-managed resource; WP_Filesystem has no output stream API.
        fclose( $out );
        exit;
    }

    public function handleRegionsGet( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
    {
        $groups = RegionRules::groups();
        $rules  = [];
        foreach ( $groups as $group ) {
            $rules[ $group ] = RegionRules::resolveDefaults( $group );
        }

        return new \WP_REST_Response(
            [
				'groups'             => $rules,
				'country_group'      => RegionRules::countryGroup(),
				'policy_version'     => (string) Config::get( 'policy_version' ),
				'reconsent_interval' => (int) Config::get( 'reconsent_interval' ),
            ]
        );
    }

    public function handleRegionsPut( \WP_REST_Request $request ): \WP_REST_Response
    {
        $body   = (array) $request->get_json_params();
        $groups = isset( $body['groups'] ) && is_array( $body['groups'] ) ? $body['groups'] : [];

        $sanitized = [];
        foreach ( $groups as $group => $rule ) {
            if ( ! is_string( $group ) || ! is_array( $rule ) ) {
                continue;
            }
            if ( ! in_array( $group, RegionRules::groups(), true ) ) {
                continue;
            }
            $model               = isset( $rule['model'] ) && in_array( $rule['model'], [ 'opt-in', 'opt-out' ], true ) ? $rule['model'] : 'opt-in';
            $sanitized[ $group ] = [
                'model'       => $model,
                'preferences' => (bool) ( $rule['preferences'] ?? false ),
                'analytics'   => (bool) ( $rule['analytics'] ?? false ),
                'marketing'   => (bool) ( $rule['marketing'] ?? false ),
            ];
        }

        update_option( 'pk_standard_consent_regions', $sanitized );

        if ( isset( $body['country_group'] ) && is_array( $body['country_group'] ) ) {
            $this->saveCountryGroup( $body['country_group'] );
        }

        if ( isset( $body['policy_version'] ) || isset( $body['reconsent_interval'] ) ) {
            $stored = get_option( 'pk_standard_consent', [] );
            $stored = is_array( $stored ) ? $stored : [];
            if ( isset( $body['policy_version'] ) ) {
                $stored['policy_version'] = sanitize_text_field( (string) $body['policy_version'] );
            }
            if ( isset( $body['reconsent_interval'] ) ) {
                $stored['reconsent_interval'] = (int) $body['reconsent_interval'];
            }
            update_option( 'pk_standard_consent', $stored );
        }

        return new \WP_REST_Response( [ 'saved' => $sanitized ] );
    }

    /**
     * Persists the admin country/state -> group map as overrides only: an entry that already
     * matches the built-in constant is dropped so the stored option stays small. Codes are
     * upper-cased; groups must be valid. The empty submission clears all overrides.
     *
     * @param array<mixed> $submitted
     */
    private function saveCountryGroup( array $submitted ): void
    {
        $valid_groups = RegionRules::groups();
        $builtin      = RegionRules::builtinCountryGroup();
        $overrides    = [];

        foreach ( $submitted as $code => $group ) {
            if ( ! is_string( $code ) || ! is_string( $group ) ) {
                continue;
            }
            $code = strtoupper( sanitize_text_field( $code ) );
            if ( '' === $code || ! in_array( $group, $valid_groups, true ) ) {
                continue;
            }
            // Keep only true overrides (new codes, or a code re-routed from its built-in group).
            if ( ! isset( $builtin[ $code ] ) || $builtin[ $code ] !== $group ) {
                $overrides[ $code ] = $group;
            }
        }

        update_option( 'pk_standard_consent_country_group', $overrides );
    }

    public function handleLogStats( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
    {
        global $wpdb;

        $table = $wpdb->prefix . 'pk_sc_consent_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed string built from $wpdb->prefix, never user input; COUNT changes per consent insert so NoCaching is intentional.
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed string; INTERVAL uses %d via prepare(); NoCaching is intentional.
        $last30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE consented_at >= NOW() - INTERVAL %d DAY", 30 ) );

        $accept_all = $this->countConsentPattern( $table, 1, 1, 1 );
        $reject_all = $this->countConsentPattern( $table, 0, 0, 0 );

        $accept_all_pct = $total > 0 ? (int) round( $accept_all * 100 / $total ) : 0;
        $reject_all_pct = $total > 0 ? (int) round( $reject_all * 100 / $total ) : 0;
        $custom_pct     = 100 - $accept_all_pct - $reject_all_pct;

        return new \WP_REST_Response(
            [
				'total'         => $total,
				'last30'        => $last30,
				'acceptAllPct'  => $accept_all_pct,
				'rejectAllPct'  => $reject_all_pct,
				'customPct'     => $custom_pct,
				'retentionDays' => (int) Config::get( 'log_retention_days' ),
            ],
            200
        );
    }

    private function countConsentPattern( string $table, int $preferences, int $analytics, int $marketing ): int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed string built from $wpdb->prefix; values are typed int parameters; NoCaching is intentional.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE preferences = %d AND analytics = %d AND marketing = %d",
                $preferences,
                $analytics,
                $marketing
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function cleanup(): void
    {
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
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, bool|float|int|string|null>
     */
    private function maskRow( array $row ): array
    {
        if ( isset( $row['visitor_hash'] ) && is_string( $row['visitor_hash'] ) ) {
            $row['visitor_hash'] = substr( $row['visitor_hash'], 0, 8 );
        }
        if ( isset( $row['ua_hash'] ) && is_string( $row['ua_hash'] ) ) {
            $row['ua_hash'] = substr( $row['ua_hash'], 0, 8 );
        }

        /** @var array<string, bool|float|int|string|null> */
        return array_map(
            static function ( mixed $v ): bool|float|int|string|null {
                if ( null === $v || is_bool( $v ) || is_int( $v ) || is_float( $v ) || is_string( $v ) ) {
                    return $v;
                }
                return '';
            },
            $row
        );
    }
}
