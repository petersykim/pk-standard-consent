<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared SSRF guard for every server-side outbound fetch in this plugin (the cookie Scanner
 * and the IP-geo provider lookup). wp_http_validate_url() rejects loopback + RFC1918 but NOT
 * the link-local range 169.254.0.0/16 — the AWS/GCP instance-metadata endpoint, the
 * highest-value SSRF target — so we additionally resolve the host and reject private +
 * reserved + link-local IPs explicitly.
 */
final class SafeUrl
{
    /**
     * SSRF-safe wp_remote_get: validates the URL, fetches with redirection DISABLED, and
     * manually follows up to $max_hops redirects — re-validating each Location through
     * isUnsafe() before following. WP's HTTP API default (redirection => 5) followed 3xx hops
     * with NO re-validation, so a URL that passed isUnsafe() could redirect to an internal /
     * metadata IP and be fetched anyway (round-6 audit F1). Callers MUST use this instead of a
     * bare wp_remote_get for any attacker-influenced URL.
     *
     * @param array<string,mixed> $args  merged into the wp_remote_get args (redirection forced 0).
     * @return array<mixed>|\WP_Error  the final WP HTTP response, or WP_Error on unsafe/failure.
     */
    public static function get( string $url, array $args = [] )
    {
        $max_hops = 3;
        $current  = $url;
        for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
            if ( self::isUnsafe( $current ) ) {
                return new \WP_Error( 'pk_sc_unsafe_url', 'Refusing to fetch an unsafe URL (SSRF guard).' );
            }
            $response = wp_remote_get( $current, array_merge( $args, [ 'redirection' => 0 ] ) );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 300 || $code >= 400 ) {
                return $response;
            }
            $location = wp_remote_retrieve_header( $response, 'location' );
            if ( ! is_string( $location ) || '' === $location ) {
                return $response; // 3xx with no Location — hand back as-is.
            }
            // Resolve a relative redirect against the current URL before re-validating.
            $current = self::resolveLocation( $current, $location );
        }
        return new \WP_Error( 'pk_sc_too_many_redirects', 'Too many redirects while fetching safely.' );
    }

    /** Resolve a possibly-relative Location header against the URL it came from. */
    private static function resolveLocation( string $base, string $location ): string
    {
        if ( preg_match( '#^https?://#i', $location ) ) {
            return $location;
        }
        $scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
        $host   = (string) wp_parse_url( $base, PHP_URL_HOST );
        if ( '' === $scheme || '' === $host ) {
            return $location;
        }
        $port = wp_parse_url( $base, PHP_URL_PORT );
        $auth = $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
        if ( str_starts_with( $location, '/' ) ) {
            return $auth . $location;
        }
        return $auth . '/' . ltrim( $location, '/' );
    }

    /** Returns true when the URL is unsafe to fetch server-side. */
    public static function isUnsafe( string $url ): bool
    {
        if ( '' === $url || false === wp_http_validate_url( $url ) ) {
            return true;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' === $host ) {
            return true;
        }
        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : self::resolveHostIps( $host );
        foreach ( $ips as $ip ) {
            if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a hostname to its A/AAAA records; an unresolvable host is treated as unsafe
     * (returns a sentinel link-local IP so the caller's reject loop fires).
     *
     * @return array<int,string>
     */
    private static function resolveHostIps( string $host ): array
    {
        $ips = [];
        $v4  = gethostbynamel( $host );
        if ( is_array( $v4 ) ) {
            $ips = $v4;
        }
        $records = function_exists( 'dns_get_record' ) ? dns_get_record( $host, DNS_AAAA ) : [];
        foreach ( (array) $records as $r ) {
            if ( ! empty( $r['ipv6'] ) ) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        return [] === $ips ? [ '169.254.0.1' ] : $ips;
    }
}
