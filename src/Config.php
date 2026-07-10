<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Config
{
    /** @var array<string, bool|string|int> */
    private static array $defaults = [
        'consent_enabled'      => true,
        'uninstall_clean'      => false,
        'policy_version'       => '1.0',
        'floating_reopen'      => false,
        'reconsent_interval'   => 90, // DAYS (the Regions UI labels + edits this in days). Converted to seconds at use in Cookie.php.
        'consent_message'      => '',
        'privacy_policy_url'   => '',
        'geo_header'           => 'CF-IPCountry',
        'geo_state_header'     => 'CF-Region-Code',
        'geo_provider_url'     => '',
        'geo_fallback_region'  => 'ROW',
        'log_retention_days'   => 365,
        'consent_mode_v2'      => true,
        'scan_urls'            => '',
        'scan_url_limit'       => 10,
        'scan_request_timeout' => 8,
        'scan_wall_limit'      => 60,
        'scan_sslverify'       => true,
        'debug_mode'           => false,
    ];

    public static function get( string $key ): bool|string|int
    {
        if ( ! array_key_exists( $key, self::$defaults ) ) {
            throw new \InvalidArgumentException( "Unknown config key: {$key}" );
        }

        $stored = get_option( 'pk_standard_consent', [] );
        $stored = is_array( $stored ) ? $stored : [];

        $value = array_key_exists( $key, $stored ) ? $stored[ $key ] : self::$defaults[ $key ];
        return is_bool( $value ) || is_string( $value ) || is_int( $value ) ? $value : self::$defaults[ $key ];
    }
}
