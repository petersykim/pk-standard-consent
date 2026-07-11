<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scanner
{
	private bool $timed_out = false;

	/**
	 * True when a crawled URL returned an auth/login interstitial (Cloudflare Access,
	 * basic-auth, an SSO wall) instead of the site's own HTML — so a "0 cookies" result
	 * means "couldn't see the page," not "clean." Surfaced so the UI can say so rather
	 * than silently reporting a false all-clear (round-4 audit F2).
	 */
	private bool $blocked = false;

	/**
	 * Coordinates the full scan: registry pass, then HTTP pass, then merge.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function run(): array
	{
		// Long scans are governed by scan_wall_limit, not PHP's max_execution_time.
		// No-op if disabled on the host, which is acceptable.
		set_time_limit( 0 );

		$start_time    = microtime( true );
		$registry_rows = $this->gatherFromRegistry();

		$raw_urls = (string) Config::get( 'scan_urls' );
		$urls     = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", $raw_urls ) )
			)
		);
		// Empty scan_urls means "front page only" — always crawl at least the home URL.
		if ( [] === $urls ) {
			$urls = [ home_url( '/' ) ];
		}
		$urls = array_slice( $urls, 0, (int) Config::get( 'scan_url_limit' ) );

		$http_sets = $this->gatherFromHttp( $urls, $start_time );

		return $this->mergeRows( $registry_rows, $http_sets['server'], $http_sets['signature'] );
	}

	public function timedOut(): bool
	{
		return $this->timed_out;
	}

	public function blocked(): bool
	{
		return $this->blocked;
	}

	/**
	 * Does the fetched body look like an auth/login interstitial rather than our own site?
	 * Cloudflare Access, WP's own login form, and generic SSO walls all replace the real
	 * page — a scan of that body finds none of the site's real cookies/scripts.
	 */
	private function looksLikeAuthWall( string $body ): bool
	{
		if ( '' === $body ) {
			return false;
		}
		$needles = [
			'Cloudflare Access',
			'cloudflareaccess.com',
			'name="loginform"',      // wp-login.php
			'id="loginform"',
			'Sign in with SSO',
		];
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $body, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scans ScriptRegistry for known provider signatures.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function gatherFromRegistry(): array
	{
		$rows      = [];
		$providers = CookieMap::providers();

		foreach ( ScriptRegistry::instance()->all() as $entry ) {
			$src = $this->str( $entry['src'] ?? '' );

			foreach ( $providers as $provider ) {
				/** @var list<string> $signatures */
				$signatures = (array) ( $provider['signatures'] ?? [] );
				if ( ! $this->srcMatchesProvider( $src, $signatures ) ) {
					continue;
				}

				/** @var list<array<string,mixed>> $cookies */
				$cookies = (array) ( $provider['cookies'] ?? [] );
				foreach ( $cookies as $cookie ) {
					$rows[] = $this->buildRow(
						$this->str( $cookie['name'] ?? '' ),
						$this->str( $provider['provider'] ?? '' ),
						$this->str( $provider['category'] ?? '' ),
						$this->str( $cookie['duration'] ?? '' ),
						$this->str( $cookie['purpose'] ?? '' ),
						'registry',
						0,
						''
					);
				}
				break;
			}
		}

		return $rows;
	}

	/**
	 * Fetches each URL and extracts cookies from Set-Cookie headers and script signatures.
	 *
	 * @param list<string> $urls
	 * @return array{server:array<int,array<string,mixed>>,signature:array<int,array<string,mixed>>}
	 */
	private function gatherFromHttp( array $urls, float $start_time ): array
	{
		$server_rows    = [];
		$signature_rows = [];
		$wall_limit     = (int) Config::get( 'scan_wall_limit' );
		$timeout        = (int) Config::get( 'scan_request_timeout' );
		$sslverify      = (bool) Config::get( 'scan_sslverify' );

		foreach ( $urls as $url ) {
			if ( ( microtime( true ) - $start_time ) > $wall_limit ) {
				$this->timed_out = true;
				break;
			}

			// SSRF guard. wp_http_validate_url alone passes the link-local metadata range
			// (169.254.0.0/16 — the AWS/GCP instance-metadata endpoint), so use the shared
			// SafeUrl guard that also resolves the host and rejects private/reserved/link-local.
			// EXEMPTION: the scanner only ever fetches THIS site's own pages (scan_urls, default
			// home_url) — scanning your own site is not an SSRF attack. On a site whose own host
			// resolves to a private IP (DDEV / staging / VPN / internal), SafeUrl::isUnsafe would
			// otherwise reject every scan URL and the admin got a false "0 cookies, all clean"
			// with no warning (real-world QA R2-002). So skip the SSRF rejection for a URL on the
			// site's OWN host; the guard still applies to any other host (geo lookups, external).
			$safe_url = esc_url_raw( $url );
			if ( '' === $safe_url ) {
				continue;
			}
			$is_own_host = self::isOwnHost( $safe_url );
			if ( ! $is_own_host && SafeUrl::isUnsafe( $safe_url ) ) {
				continue;
			}

			// SafeUrl::get follows redirects with re-validation at every hop — a bare
			// wp_remote_get followed a 3xx to an internal/metadata IP unchecked (round-6 F1).
			// The own-host exemption must also reach SafeUrl::get() itself, not just this
			// pre-check: SafeUrl::get() re-validates every hop internally with its own
			// isUnsafe() call, so a privately-resolving own-host URL was rejected again right
			// there regardless of the pass above, and the scan silently returned zero results
			// (real-world QA round 3 — false all-clean on DDEV/staging).
			$response = SafeUrl::get(
				$safe_url,
				[
					'timeout'   => $timeout,
					'sslverify' => $sslverify,
				],
				$is_own_host ? (string) wp_parse_url( $safe_url, PHP_URL_HOST ) : ''
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			// If the crawler got an auth wall instead of our page, flag it so a "0 cookies"
			// result is reported as "couldn't see the page," not a false all-clear (F2).
			if ( $this->looksLikeAuthWall( (string) wp_remote_retrieve_body( $response ) ) ) {
				$this->blocked = true;
				continue;
			}

			$this->collectServerCookies( $response, $server_rows );
			$this->collectSignatureCookies( $response, $safe_url, $signature_rows );
		}

		return [
			'server'    => $server_rows,
			'signature' => $signature_rows,
		];
	}

	/**
	 * Parses Set-Cookie headers and resolves each name against CookieMap.
	 *
	 * @param array<string,mixed>|\WP_Error $response
	 * @param array<int,array<string,mixed>> $rows Accumulator, passed by reference.
	 */
	private function collectServerCookies( array|\WP_Error $response, array &$rows ): void
	{
		// wp_remote_retrieve_header (singular) fetches a named header; returns string|array.
		$raw = wp_remote_retrieve_header( $response, 'set-cookie' );
		/** @var list<string> $headers */
		$headers = is_array( $raw ) ? array_values( $raw ) : ( '' !== $raw ? [ $raw ] : [] );

		foreach ( $headers as $header ) {
			// explode, not strtok: strtok pollutes PHP's global tokenizer state for the rest of the request.
			$cookie_name = trim( explode( '=', $header, 2 )[0] );
			if ( '' === $cookie_name ) {
				continue;
			}

			$match = CookieMap::lookup( $cookie_name );
			if ( null === $match ) {
				continue;
			}

			$flag   = ( 'necessary' !== $match['category'] ) ? 1 : 0;
			$rows[] = $this->buildRow(
				$cookie_name,
				$match['provider'],
				$match['category'],
				$match['duration'],
				$match['purpose'],
				'server',
				$flag,
				''
			);
		}
	}

	/**
	 * Scans the response body for provider script signatures.
	 *
	 * @param array<string,mixed>|\WP_Error $response
	 * @param array<int,array<string,mixed>> $rows Accumulator, passed by reference.
	 */
	private function collectSignatureCookies( array|\WP_Error $response, string $url, array &$rows ): void
	{
		$body      = wp_remote_retrieve_body( $response );
		$providers = CookieMap::providers();

		foreach ( $providers as $provider ) {
			/** @var list<string> $signatures */
			$signatures = (array) ( $provider['signatures'] ?? [] );
			if ( ! $this->srcMatchesProvider( $body, $signatures ) ) {
				continue;
			}

			/** @var list<array<string,mixed>> $cookies */
			$cookies = (array) ( $provider['cookies'] ?? [] );
			foreach ( $cookies as $cookie ) {
				$rows[] = $this->buildRow(
					$this->str( $cookie['name'] ?? '' ),
					$this->str( $provider['provider'] ?? '' ),
					$this->str( $provider['category'] ?? '' ),
					$this->str( $cookie['duration'] ?? '' ),
					$this->str( $cookie['purpose'] ?? '' ),
					'signature',
					0,
					$url
				);
			}
		}
	}

	/**
	 * Merges row sets; higher-priority source wins on duplicate cookie_name.
	 * Priority: registry=3, server=2, signature=1.
	 *
	 * @param array<int,array<string,mixed>> ...$sources
	 * @return array<int,array<string,mixed>>
	 */
	private function mergeRows( array ...$sources ): array
	{
		$source_order = [
			'registry'  => 3,
			'server'    => 2,
			'signature' => 1,
		];
		/** @var array<string,array<string,mixed>> $keyed */
		$keyed = [];

		foreach ( $sources as $rows ) {
			foreach ( $rows as $row ) {
				$key      = $this->str( $row['cookie_name'] ?? '' );
				$priority = $source_order[ $this->str( $row['source'] ?? '' ) ] ?? 0;

				if ( ! isset( $keyed[ $key ] ) ) {
					$keyed[ $key ] = $row;
					continue;
				}

				// A registry row carries compliance_flag=0, so when registry beats a server row,
				// a registered cookie served before consent loses its server-layer violation flag.
				// Intentional: the registry category is admin-asserted and authoritative.
				$existing = $source_order[ $this->str( $keyed[ $key ]['source'] ?? '' ) ] ?? 0;
				if ( $priority > $existing ) {
					$keyed[ $key ] = $row;
				}
			}
		}

		return array_values( $keyed );
	}

	/**
	 * @param list<string> $signatures
	 */
	private function srcMatchesProvider( string $src, array $signatures ): bool
	{
		foreach ( $signatures as $sig ) {
			if ( str_contains( $src, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Safely coerces a mixed value to string; avoids "(string) mixed" PHPStan error at level 9.
	 */
	private function str( mixed $value ): string
	{
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildRow(
		string $cookie_name,
		string $provider,
		string $category,
		string $duration,
		string $purpose,
		string $source,
		int $compliance_flag,
		string $first_seen_url
	): array {
		return [
			'cookie_name'     => $cookie_name,
			'provider'        => $provider,
			'category'        => $category,
			'duration'        => $duration,
			'purpose'         => $purpose,
			'source'          => $source,
			'compliance_flag' => $compliance_flag,
			'first_seen_url'  => $first_seen_url,
		];
	}

	/**
	 * True when $url is on THIS site's own host (home_url). Scanning your own site is not an
	 * SSRF target, so the scanner exempts its own host from the private-IP rejection — otherwise
	 * a site whose host resolves privately (DDEV / staging / VPN) can never scan itself and the
	 * admin gets a false "all clean". Host-only comparison, case-insensitive; port/scheme ignored.
	 */
	private static function isOwnHost( string $url ): bool
	{
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$own_host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return $host !== '' && $host === $own_host;
	}
}
