<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CookieMap
{
	/**
	 * @var list<array{provider:string,category:string,signatures:list<string>,cookies:list<array{name:string,pattern:string|null,duration:string,purpose:string}>}>
	 */
	private const MAP = [
		[
			'provider'   => 'ga4',
			'category'   => 'analytics',
			'signatures' => [
				'google-analytics.com/g/collect',
				'gtag/js',
				'googletagmanager.com/gtag',
				'googletagmanager.com/gtm.js',
			],
			'cookies'    => [
				[
					'name'     => '_ga',
					'pattern'  => null,
					'duration' => '2 years',
					'purpose'  => 'Distinguishes users',
				],
				[
					'name'     => '_ga_',
					'pattern'  => '*',
					'duration' => '2 years',
					'purpose'  => 'Persists session state',
				],
				[
					'name'     => '_gid',
					'pattern'  => null,
					'duration' => '24 hours',
					'purpose'  => 'Distinguishes users',
				],
				[
					'name'     => '_gat',
					'pattern'  => null,
					'duration' => '1 minute',
					'purpose'  => 'Throttles request rate',
				],
			],
		],
		[
			'provider'   => 'meta_pixel',
			'category'   => 'marketing',
			'signatures' => [
				'connect.facebook.net/en_US/fbevents.js',
				'connect.facebook.net',
			],
			'cookies'    => [
				[
					'name'     => '_fbp',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Stores and tracks visits',
				],
				[
					'name'     => '_fbc',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Stores last ad click',
				],
			],
		],
		[
			'provider'   => 'hotjar',
			'category'   => 'analytics',
			'signatures' => [
				'static.hotjar.com/c/hotjar-',
				'hotjar.com',
			],
			'cookies'    => [
				[
					'name'     => '_hjSession_',
					'pattern'  => '*',
					'duration' => '30 minutes',
					'purpose'  => 'Holds current session data',
				],
				[
					'name'     => '_hjSessionUser_',
					'pattern'  => '*',
					'duration' => '1 year',
					'purpose'  => 'Holds Hotjar user ID',
				],
				[
					'name'     => '_hjIncludedInSample',
					'pattern'  => null,
					'duration' => '365 days',
					'purpose'  => 'Determines sample inclusion',
				],
			],
		],
		[
			'provider'   => 'linkedin',
			'category'   => 'marketing',
			'signatures' => [
				'snap.licdn.com/li.lms-analytics',
				'linkedin.com/li.lms-analytics',
				'platform.linkedin.com',
			],
			'cookies'    => [
				[
					'name'     => 'li_sugr',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Stores browser ID',
				],
				[
					'name'     => 'lidc',
					'pattern'  => null,
					'duration' => '24 hours',
					'purpose'  => 'Facilitates data center selection',
				],
				[
					'name'     => 'UserMatchHistory',
					'pattern'  => null,
					'duration' => '30 days',
					'purpose'  => 'LinkedIn audience matching',
				],
				[
					'name'     => 'AnalyticsSyncHistory',
					'pattern'  => null,
					'duration' => '30 days',
					'purpose'  => 'Stores sync info',
				],
			],
		],
		[
			'provider'   => 'google_ads',
			'category'   => 'marketing',
			'signatures' => [
				'googleadservices.com/pagead/conversion',
				'googleads.g.doubleclick.net',
			],
			'cookies'    => [
				[
					'name'     => '_gcl_aw',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Stores click info for conversion',
				],
				[
					'name'     => '_gcl_au',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Experiments with ad efficiency',
				],
				[
					'name'     => '_gcl_gs',
					'pattern'  => null,
					'duration' => '90 days',
					'purpose'  => 'Google conversion store',
				],
				[
					'name'     => '_gac_',
					'pattern'  => '*',
					'duration' => '90 days',
					'purpose'  => 'Google Ads campaign conversion linker',
				],
			],
		],
		[
			'provider'   => 'intercom',
			'category'   => 'marketing',
			'signatures' => [
				'widget.intercom.io/widget',
				'js.intercomcdn.com',
			],
			'cookies'    => [
				[
					'name'     => 'intercom-id-',
					'pattern'  => '*',
					'duration' => '9 months',
					'purpose'  => 'Anonymous visitor identifier',
				],
				[
					'name'     => 'intercom-session-',
					'pattern'  => '*',
					'duration' => '1 week',
					'purpose'  => 'Identifier for each session',
				],
			],
		],
		[
			'provider'   => 'hubspot',
			'category'   => 'marketing',
			'signatures' => [
				'js.hs-scripts.com',
				'js.hsforms.net',
				'hs-analytics.net',
			],
			'cookies'    => [
				[
					'name'     => '__hstc',
					'pattern'  => null,
					'duration' => '6 months',
					'purpose'  => 'Main tracking cookie',
				],
				[
					'name'     => 'hubspotutk',
					'pattern'  => null,
					'duration' => '6 months',
					'purpose'  => 'Tracks visitor identity',
				],
				[
					'name'     => '__hssc',
					'pattern'  => null,
					'duration' => '30 minutes',
					'purpose'  => 'Tracks sessions',
				],
				[
					'name'     => '__hssrc',
					'pattern'  => null,
					'duration' => 'Session',
					'purpose'  => 'Detects new sessions',
				],
			],
		],
		[
			'provider'   => 'crisp',
			'category'   => 'preferences',
			'signatures' => [
				'client.crisp.chat/l.js',
				'crisp.chat',
			],
			'cookies'    => [
				[
					'name'     => 'crisp-client',
					'pattern'  => '*',
					'duration' => '6 months',
					'purpose'  => 'Stores Crisp session data',
				],
			],
		],
		[
			'provider'   => 'woocommerce_attribution',
			'category'   => 'marketing',
			'signatures' => [
				'sourcebuster/sourcebuster.min.js',
				'order-attribution.min.js',
			],
			'cookies'    => [
				[
					'name'     => 'sbjs_migrations',
					'pattern'  => null,
					'duration' => '6 months',
					'purpose'  => 'Sourcebuster schema version',
				],
				[
					'name'     => 'sbjs_current',
					'pattern'  => '*',
					'duration' => '6 months',
					'purpose'  => 'Current traffic source attribution',
				],
				[
					'name'     => 'sbjs_first',
					'pattern'  => '*',
					'duration' => '6 months',
					'purpose'  => 'First traffic source attribution',
				],
				[
					'name'     => 'sbjs_session',
					'pattern'  => null,
					'duration' => '30 minutes',
					'purpose'  => 'Session page counter',
				],
				[
					'name'     => 'sbjs_udata',
					'pattern'  => null,
					'duration' => '6 months',
					'purpose'  => 'Visitor user agent and visit count',
				],
			],
		],
		[
			'provider'   => 'microsoft_clarity',
			'category'   => 'analytics',
			'signatures' => [
				'clarity.ms/tag',
				'www.clarity.ms',
			],
			'cookies'    => [
				[
					'name'     => '_clck',
					'pattern'  => null,
					'duration' => '1 year',
					'purpose'  => 'Persists Clarity user ID',
				],
				[
					'name'     => '_clsk',
					'pattern'  => null,
					'duration' => '1 day',
					'purpose'  => 'Connects page views to a session',
				],
				[
					'name'     => 'CLID',
					'pattern'  => null,
					'duration' => '1 year',
					'purpose'  => 'Identifies first-time visitor',
				],
			],
		],
	];

	/**
	 * Cookie name prefixes that are always classified as necessary.
	 * Checked via str_starts_with before the provider map.
	 *
	 * @var list<string>
	 */
	private const NECESSARY = [
		'wordpress_',
		'wp-settings-',
		'PHPSESSID',
		'pk_sc_consent',
	];

	/**
	 * Resolve a cookie name to its classification.
	 * Returns null when the cookie is unknown.
	 *
	 * @return array{provider:string,category:string,duration:string,purpose:string}|null
	 */
	public static function lookup( string $cookie_name ): ?array
	{
		// Necessary cookies bypass the provider map entirely.
		foreach ( self::NECESSARY as $prefix ) {
			if ( str_starts_with( $cookie_name, $prefix ) ) {
				return [
					'provider' => 'necessary',
					'category' => 'necessary',
					'duration' => 'session',
					'purpose'  => 'Core WordPress functionality',
				];
			}
		}

		// Exact-name match first, then wildcard-suffix.
		foreach ( self::MAP as $provider ) {
			foreach ( $provider['cookies'] as $cookie ) {
				if ( null === $cookie['pattern'] && $cookie['name'] === $cookie_name ) {
					return [
						'provider' => $provider['provider'],
						'category' => $provider['category'],
						'duration' => $cookie['duration'],
						'purpose'  => $cookie['purpose'],
					];
				}
			}
		}

		foreach ( self::MAP as $provider ) {
			foreach ( $provider['cookies'] as $cookie ) {
				if ( '*' === $cookie['pattern'] && str_starts_with( $cookie_name, $cookie['name'] ) ) {
					return [
						'provider' => $provider['provider'],
						'category' => $provider['category'],
						'duration' => $cookie['duration'],
						'purpose'  => $cookie['purpose'],
					];
				}
			}
		}

		return null;
	}

	/**
	 * Returns the full provider map, with a filter seam for extensibility.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function providers(): array
	{
		return apply_filters( 'pk_sc_cookie_map', self::MAP ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook name is prefixed pk_sc_
	}
}
