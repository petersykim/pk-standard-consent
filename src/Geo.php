<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Geo
{
    /**
     * Resolves a region key for the current visitor.
     *
     * Order: (1) the CDN geo header (country), upgraded to a 'US-XX' state code when a
     * US visitor also carries the state header — so California reaches the CCPA opt-out
     * banner; (2) a real IP-geo provider lookup over the configured URL when no header is
     * present; (3) the configured fallback region code.
     */
    public static function detect(): string
    {
        // @security-note: CF-IPCountry is spoofable if traffic bypasses Cloudflare (e.g. direct origin hit); mitigate via origin IP lockdown to CF ranges or a shared-secret CF header. One-directional risk: spoofing only gets a visitor LESS tracking on themselves, never more.
        $country = self::headerCountry();
        $country = (string) apply_filters( 'pk_sc_geo_country', $country );

        if ( '' !== $country && 'XX' !== $country ) {
            return self::withState( $country );
        }

        $provider = self::providerCountry();
        if ( '' !== $provider && 'XX' !== $provider ) {
            return self::withState( $provider );
        }

        return (string) Config::get( 'geo_fallback_region' );
    }

    /**
     * Resolves the real client IP for rate-limiting and visitor-hashing.
     *
     * Behind Cloudflare (which this plugin targets), $_SERVER['REMOTE_ADDR'] is the CF edge
     * IP, not the visitor — so distinct visitors sharing a PoP would share one rate bucket
     * and their visitor_hash would collapse toward the edge IP. Prefer CF-Connecting-IP, then
     * the first X-Forwarded-For hop, then REMOTE_ADDR. Each candidate is validated as a real IP.
     *
     * The X-Forwarded-For hop is additionally required to be a PUBLIC address: a private/reserved
     * first hop (172.16/12, 10/8, 192.168/16, etc.) is a proxy/gateway, not the visitor — using it
     * would collapse the rate bucket for every visitor behind that gateway, which is the very bug
     * we're fixing. In that case fall through to REMOTE_ADDR. Safe to trust for a throttle key + a
     * hash input (not an auth decision) even though the headers are spoofable.
     */
    public static function clientIp(): string
    {
        $cf = self::serverHeader( 'CF-Connecting-IP' );
        if ( '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
            return $cf;
        }

        $xff = self::serverHeader( 'X-Forwarded-For' );
        if ( '' !== $xff ) {
            $first = trim( explode( ',', $xff )[0] );
            if ( false !== filter_var( $first, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $first;
            }
        }

        return self::serverHeader( 'REMOTE_ADDR', 'HTTP_' );
    }

    /**
     * Upgrades a 'US' country to a 'US-XX' state code when the configured state header
     * carries a two-letter US state (CF-Region-Code on Cloudflare). Non-US, or no state
     * header, returns the country unchanged.
     */
    private static function withState( string $country ): string
    {
        if ( 'US' !== $country ) {
            return $country;
        }

        $header = (string) Config::get( 'geo_state_header' );
        $state  = self::serverHeader( $header );
        $state  = (string) apply_filters( 'pk_sc_geo_state', substr( $state, 0, 3 ) );

        return preg_match( '/^[A-Z]{2}$/', $state ) ? 'US-' . $state : $country;
    }

    /** Reads the country code from the configured geo header. */
    private static function headerCountry(): string
    {
        $country = self::serverHeader( (string) Config::get( 'geo_header' ) );
        return substr( $country, 0, 3 );
    }

    /**
     * Fetches the country code from the configured IP-geo provider URL. The URL must carry
     * an {ip} placeholder; it is replaced with the visitor IP and the JSON response is
     * parsed for a country code. SSRF-guarded via wp_http_validate_url (reuses the scanner's
     * guard) so an admin-set URL cannot reach internal/metadata hosts.
     */
    private static function providerCountry(): string
    {
        $url = (string) Config::get( 'geo_provider_url' );
        $ip  = self::serverHeader( 'REMOTE_ADDR', 'HTTP_' );
        if ( '' === $url || '' === $ip || ! str_contains( $url, '{ip}' ) ) {
            return '';
        }

        $request = esc_url_raw( str_replace( '{ip}', rawurlencode( $ip ), $url ) );
        if ( '' === $request || SafeUrl::isUnsafe( $request ) ) {
            return '';
        }

        // SafeUrl::get re-validates each redirect hop — this fires on every anonymous visitor's
        // page load, so a provider that redirects to an internal IP must not be followed blindly
        // (round-6 audit F1).
        $response = SafeUrl::get( $request, [ 'timeout' => 3 ] );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        return self::parseCountry( (string) wp_remote_retrieve_body( $response ) );
    }

    /**
     * Pulls a two/three-letter country code out of an IP-geo JSON body. Accepts the common
     * provider keys (country_code, countryCode, country) and ignores anything else.
     */
    private static function parseCountry( string $body ): string
    {
        if ( '' === $body ) {
            return '';
        }
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return '';
        }

        foreach ( [ 'country_code', 'countryCode', 'country' ] as $key ) {
            $value = $data[ $key ] ?? '';
            if ( is_string( $value ) && preg_match( '/^[A-Za-z]{2,3}$/', $value ) ) {
                return strtoupper( $value );
            }
        }
        return '';
    }

    /** Reads, unslashes, sanitizes and upper-cases a request header value. */
    private static function serverHeader( string $name, string $prefix = 'HTTP_' ): string
    {
        $key = 'REMOTE_ADDR' === $name ? 'REMOTE_ADDR' : $prefix . str_replace( '-', '_', strtoupper( $name ) );

        $value = sanitize_text_field(
            wp_unslash( $_SERVER[ $key ] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitize_text_field handles it
        );

        return 'REMOTE_ADDR' === $name ? $value : strtoupper( $value );
    }
}
