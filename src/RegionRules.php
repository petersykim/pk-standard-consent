<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RegionRules
{
    /**
     * Maps ISO country codes to consent group slugs.
     * US is NOT mapped — falls through to ROW until state-level detection lands.
     * Admin can override US by creating a 'US' entry in pk_standard_consent_regions
     * that maps to 'CA' (CCPA) if they want to treat all US traffic as opt-out.
     *
     * @var array<string, string>
     */
    private const COUNTRY_GROUP = [
        // EU member states
        'AT' => 'EU',
		'BE' => 'EU',
		'BG' => 'EU',
		'HR' => 'EU',
		'CY' => 'EU',
        'CZ' => 'EU',
		'DK' => 'EU',
		'EE' => 'EU',
		'FI' => 'EU',
		'FR' => 'EU',
        'DE' => 'EU',
		'GR' => 'EU',
		'HU' => 'EU',
		'IE' => 'EU',
		'IT' => 'EU',
        'LV' => 'EU',
		'LT' => 'EU',
		'LU' => 'EU',
		'MT' => 'EU',
		'NL' => 'EU',
        'PL' => 'EU',
		'PT' => 'EU',
		'RO' => 'EU',
		'SK' => 'EU',
		'SI' => 'EU',
        'ES' => 'EU',
		'SE' => 'EU',
        // EEA non-EU
        'IS' => 'EU',
		'LI' => 'EU',
		'NO' => 'EU',
        // UK post-Brexit
        'GB' => 'UK',
        // US -> CCPA opt-out group by default, so a US visitor reaches the Do-Not-Sell
        // banner without manual config (recording/analytics run until they opt out — CCPA-legal).
        'US'    => 'CA',
        // California specifically is OPT-IN, not opt-out. Session recording (Clarity) and analytics
        // before an explicit choice carry real wiretapping/CIPA litigation exposure that is
        // concentrated in California — so a CA visitor is denied-until-granted like the EU, while
        // the rest of the US keeps the opt-out default. Geo::detect emits 'US-CA' for a detected
        // California visitor (CF-Region-Code). Peter's call, 2026-07-17: keep US data, carve out CA.
        'US-CA' => 'US_CALIF',
    ];

    /**
     * Default consent model per group. All non-CA regions start opt-in / all-denied.
     *
     * @var array<string, array{model: string, preferences: bool, analytics: bool, marketing: bool}>
     */
    private const DEFAULTS = [
        'EU'  => [
			'model'       => 'opt-in',
			'preferences' => false,
			'analytics'   => false,
			'marketing'   => false,
		],
        'UK'  => [
			'model'       => 'opt-in',
			'preferences' => false,
			'analytics'   => false,
			'marketing'   => false,
		],
        'ROW' => [
			'model'       => 'opt-in',
			'preferences' => false,
			'analytics'   => false,
			'marketing'   => false,
		],
        'CA'  => [
			'model'       => 'opt-out',
			'preferences' => true,
			'analytics'   => true,
			'marketing'   => true,
		],
        // California (the state) — opt-in / all-denied, same posture as the EU. Distinct from the
        // 'CA' group above, which is CCPA (the US opt-out model). See the US-CA route note above.
        'US_CALIF' => [
			'model'       => 'opt-in',
			'preferences' => false,
			'analytics'   => false,
			'marketing'   => false,
		],
    ];

    public static function resolveGroup( string $country_or_group ): string
    {
        $map = self::countryGroup();
        if ( isset( $map[ $country_or_group ] ) ) {
            return $map[ $country_or_group ];
        }

        // An unmapped 'US-XX' state code falls back to its country group, so a non-California
        // US visitor still reaches whatever the 'US' row routes to (CCPA opt-out by default)
        // rather than silently dropping to ROW/opt-in.
        if ( str_contains( $country_or_group, '-' ) ) {
            $country = explode( '-', $country_or_group, 2 )[0];
            if ( isset( $map[ $country ] ) ) {
                return $map[ $country ];
            }
        }

        if ( isset( self::DEFAULTS[ $country_or_group ] ) ) {
            return $country_or_group;
        }

        return 'ROW';
    }

    /**
     * @return array{model: string, preferences: bool, analytics: bool, marketing: bool}
     */
    public static function resolveDefaults( string $group ): array
    {
        $base = self::DEFAULTS[ $group ] ?? self::DEFAULTS['ROW'];

        $overrides = get_option( 'pk_standard_consent_regions', [] );
        $overrides = is_array( $overrides ) ? $overrides : [];
        $override  = isset( $overrides[ $group ] ) && is_array( $overrides[ $group ] ) ? $overrides[ $group ] : [];

        return [
            'model'       => isset( $override['model'] ) && is_string( $override['model'] ) ? $override['model'] : $base['model'],
            'preferences' => isset( $override['preferences'] ) ? (bool) $override['preferences'] : $base['preferences'],
            'analytics'   => isset( $override['analytics'] ) ? (bool) $override['analytics'] : $base['analytics'],
            'marketing'   => isset( $override['marketing'] ) ? (bool) $override['marketing'] : $base['marketing'],
        ];
    }

    /**
     * The effective country/state -> group map: the built-in constant merged with the
     * admin-saved overrides (pk_standard_consent_country_group). Admin entries win, so an
     * admin can re-route US, add a missing country, or map a US state to a different group.
     * Keys are upper-cased ISO codes (or 'US-XX' state codes); values must be valid groups.
     *
     * @return array<string, string>
     */
    public static function countryGroup(): array
    {
        $map = self::builtinCountryGroup();

        $overrides = get_option( 'pk_standard_consent_country_group', [] );
        if ( ! is_array( $overrides ) ) {
            return $map;
        }

        $valid = self::groups();
        foreach ( $overrides as $code => $group ) {
            if ( is_string( $code ) && '' !== $code && is_string( $group ) && in_array( $group, $valid, true ) ) {
                $map[ strtoupper( $code ) ] = $group;
            }
        }

        return $map;
    }

    /**
     * The built-in (un-overridden) country/state -> group map. Distinct from countryGroup(),
     * which merges admin overrides on top.
     *
     * @return array<string, string>
     */
    public static function builtinCountryGroup(): array
    {
        return self::COUNTRY_GROUP;
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return array_keys( self::DEFAULTS );
    }
}
