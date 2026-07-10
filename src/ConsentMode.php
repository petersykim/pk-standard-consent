<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ConsentMode
{
    /**
     * Map of consent category => GCM v2 signals.
     * 'necessary' is excluded — security_storage is always 'granted'.
     *
     * @var array<string, list<string>>
     */
    private const SIGNAL_MAP = [
        'preferences' => [ 'functionality_storage', 'personalization_storage' ],
        'analytics'   => [ 'analytics_storage' ],
        'marketing'   => [ 'ad_storage', 'ad_user_data', 'ad_personalization' ],
    ];

    // GCM v2 recommended debounce (ms) so tags hold briefly for a consent update.
    private const WAIT_FOR_UPDATE = 500;

    /** @return array{preferences: bool, analytics: bool, marketing: bool} */
    private static function regionDefaults(): array
    {
        $country = Geo::detect();
        $group   = RegionRules::resolveGroup( $country );
        $rule    = RegionRules::resolveDefaults( $group );

        return [
            'preferences' => $rule['preferences'],
            'analytics'   => $rule['analytics'],
            'marketing'   => $rule['marketing'],
        ];
    }

    public function emitConsentDefault(): void
    {
        if ( ! (bool) Config::get( 'consent_mode_v2' ) ) {
            return;
        }

        $payload   = Cookie::read();
        $stale     = null !== $payload && Cookie::isStale( $payload );
        $use_grant = null !== $payload && ! $stale;

        $signals = [ 'security_storage' => 'granted' ];
        $grants  = $use_grant && is_array( $payload['c'] ?? null ) ? $payload['c'] : self::regionDefaults();

        foreach ( self::SIGNAL_MAP as $category => $gcm_signals ) {
            $granted = ! empty( $grants[ $category ] );
            $value   = $granted ? 'granted' : 'denied';
            foreach ( $gcm_signals as $signal ) {
                $signals[ $signal ] = $value;
            }
        }

        $signals['wait_for_update'] = self::WAIT_FOR_UPDATE;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- signal values are hardcoded strings ('granted'/'denied'/500), not user input; wp_json_encode output is safe to emit directly.
        echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'consent\',\'default\',' . (string) wp_json_encode( $signals ) . ');</script>' . "\n";
    }

    public function emitGlobal(): void
    {
        $payload   = Cookie::read();
        $stale     = null !== $payload && Cookie::isStale( $payload );
        $use_grant = null !== $payload && ! $stale;

        $defaults = self::regionDefaults();
        $c        = $use_grant && is_array( $payload['c'] ?? null ) ? $payload['c'] : $defaults;
        $granted  = [
            'preferences' => (bool) ( $c['preferences'] ?? $defaults['preferences'] ),
            'analytics'   => (bool) ( $c['analytics'] ?? $defaults['analytics'] ),
            'marketing'   => (bool) ( $c['marketing'] ?? $defaults['marketing'] ),
        ];

        $group = RegionRules::resolveGroup( Geo::detect() );
        $rule  = RegionRules::resolveDefaults( $group );

        $data = (string) wp_json_encode(
            [
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                // rest_url() is NOT wrapped in esc_url() — esc_url double-encodes '&' inside JSON and breaks fetch.
                'restUrl'       => rest_url( 'pk-standard-consent/v1' ),
                'granted'       => $granted,
                'stale'         => null !== $payload && Cookie::isStale( $payload ),
                // Authoritative "already consented, don't re-show the banner" flag.
                // The consent cookie is HttpOnly, so the front-end cannot read it directly.
                'hasConsent'    => null !== $payload && ! Cookie::isStale( $payload ),
                'version'       => Config::get( 'policy_version' ),
                'consentModeV2' => (bool) Config::get( 'consent_mode_v2' ),
                'region'        => $group,
                'model'         => $rule['model'],
                'categories'    => self::buildCategoryData(),
                // Debug console logging (B-4) is gated on BOTH the admin flag AND WP_DEBUG,
                // so it can never leak to a production visitor with WP_DEBUG off.
                'debug'         => (bool) Config::get( 'debug_mode' ) && defined( 'WP_DEBUG' ) && WP_DEBUG,
            ]
        );

        $nonce_attr = (string) apply_filters( 'pk_sc_global_nonce', '' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data is wp_json_encode output; $nonce_attr is a CSP nonce passed through a filter for inline-script CSP support; neither is user-controlled HTML.
        printf( '<script%s>window.PKStandardConsent=%s;</script>' . "\n", '' !== $nonce_attr ? ' nonce="' . esc_attr( $nonce_attr ) . '"' : '', $data );
    }

    /**
     * Groups SCANNED cookies by effective category for the front-end preference center.
     * Sources from pk_sc_scan_results so only cookies actually detected on this site appear.
     * Returns empty cookies arrays when no scan has run — never falls back to the full catalog.
     *
     * @return array<string, array{description: string, cookies: list<array{name: string, provider: string, purpose: string, duration: string}>}>
     */
    private static function buildCategoryData(): array
    {
        $descs = self::categoryDescriptions();
        // Seed all four categories so the front-end always receives a complete shape.
        $result = self::emptyCategoryShell( $descs );

        // loadAll() returns [] when the table is missing or empty — both are safe/graceful.
        $rows = ( new ScanResults() )->loadAll();

        foreach ( $rows as $row ) {
            // effective_category is COALESCE(category_override, category) from the query.
            $cat = is_string( $row->effective_category ?? null ) ? $row->effective_category : '';
            if ( ! isset( $result[ $cat ] ) ) {
                continue; // unknown/unmapped category — skip rather than create a stray key.
            }

            $result[ $cat ]['cookies'][] = [
                'name'     => is_string( $row->cookie_name ?? null ) ? $row->cookie_name : '',
                'provider' => is_string( $row->provider ?? null ) ? $row->provider : '',
                'purpose'  => is_string( $row->purpose ?? null ) ? $row->purpose : '',
                'duration' => is_string( $row->duration ?? null ) ? $row->duration : '',
            ];
        }

        return $result;
    }

    /**
     * Returns the four category keys pre-populated with descriptions and empty cookie lists.
     * Ensures the front-end always receives all categories even when no scan has run.
     *
     * @param array<string, string> $descs
     * @return array<string, array{description: string, cookies: list<array{name: string, provider: string, purpose: string, duration: string}>}>
     */
    private static function emptyCategoryShell( array $descs ): array
    {
        $result = [];
        foreach ( Category::cases() as $cat ) {
            $result[ $cat->value ] = [
                'description' => $descs[ $cat->value ] ?? '',
                'cookies'     => [],
            ];
        }
        return $result;
    }

    /**
     * Admin-saved category descriptions, keyed by category slug.
     * Same source AdminRest::handleCategoriesGet reads so the preference center
     * shows the copy the admin actually wrote, not a blank line.
     *
     * @return array<string, string>
     */
    private static function categoryDescriptions(): array
    {
        $stored = get_option( 'pk_standard_consent_categories', [] );
        $stored = is_array( $stored ) ? $stored : [];

        $out = [];
        foreach ( $stored as $slug => $entry ) {
            if ( ! is_string( $slug ) || ! is_array( $entry ) ) {
                continue;
            }
            $description  = $entry['description'] ?? '';
            $out[ $slug ] = is_string( $description ) ? $description : '';
        }

        return $out;
    }
}
