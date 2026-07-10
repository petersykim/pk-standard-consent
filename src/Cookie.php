<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cookie
{
    private const NAME = 'pk_sc_consent';

    /** @param array<string, bool> $grants */
    public static function write( array $grants, string $version ): void
    {
        $payload = [
            'v' => $version,
            't' => time(),
            'c' => [
                'necessary'   => true,
                'preferences' => (bool) $grants['preferences'],
                'analytics'   => (bool) $grants['analytics'],
                'marketing'   => (bool) $grants['marketing'],
            ],
        ];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $json = json_encode( $payload );
        if ( false === $json ) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $b64 = base64_encode( $json );
        // Version in the key invalidates all consent cookies on plugin upgrade, forcing re-consent.
        $hash = hash_hmac( 'sha256', $json, wp_salt( 'auth' ) . PK_SC_VERSION );

        // reconsent_interval is stored in DAYS (matches the Regions UI). Convert to seconds.
        $interval = (int) Config::get( 'reconsent_interval' ) * DAY_IN_SECONDS;

        setcookie(
            self::NAME,
            $b64 . '.' . $hash,
            [
                'expires'  => time() + $interval,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public static function read(): ?array
    {
        $raw = isset( $_COOKIE[ self::NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) )
            : '';

        if ( '' === $raw ) {
            return null;
        }

        $dot = strrpos( $raw, '.' );
        if ( false === $dot ) {
            return null;
        }

        $b64      = substr( $raw, 0, $dot );
        $received = substr( $raw, $dot + 1 );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $json = base64_decode( $b64, true );

        if ( false === $json ) {
            return null;
        }

        $expected = hash_hmac( 'sha256', $json, wp_salt( 'auth' ) . PK_SC_VERSION );
        if ( ! hash_equals( $expected, $received ) ) {
            return null;
        }

        $data = json_decode( $json, true );

        if ( ! is_array( $data ) || ! isset( $data['v'], $data['t'], $data['c'] ) || ! is_array( $data['c'] ) ) {
            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    public static function isStale( array $payload ): bool
    {
        // reconsent_interval is stored in DAYS (matches the Regions UI). Convert to seconds.
        $interval  = (int) Config::get( 'reconsent_interval' ) * DAY_IN_SECONDS;
        $timestamp = is_int( $payload['t'] ) ? $payload['t'] : 0;
        $version   = is_string( $payload['v'] ) ? $payload['v'] : '';
        $expired   = ( time() - $timestamp ) > $interval;
        $outdated  = version_compare( $version, (string) Config::get( 'policy_version' ), '<' );

        return $expired || $outdated;
    }
}
