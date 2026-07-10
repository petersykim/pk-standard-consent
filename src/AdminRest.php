<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminRest
{
	use AdminAuth;

	private const NS = 'pk-standard-consent/v1';

	public function register(): void
	{
		register_rest_route(
			self::NS,
			'/admin/categories',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleCategoriesGet' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/categories',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'handleCategoriesPut' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/settings',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleSettingsGet' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/settings',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'handleSettingsPut' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/log',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'handleLogDelete' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/log/visitor',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'handleVisitorErase' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);
	}

	public function handleCategoriesGet( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		$stored = get_option( 'pk_standard_consent_categories', [] );
		$stored = is_array( $stored ) ? $stored : [];

		$categories = [];
		foreach ( Category::cases() as $case ) {
			$slug        = $case->value;
			$entry       = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) ? $stored[ $slug ] : [];
			$description = isset( $entry['description'] ) && is_string( $entry['description'] ) ? $entry['description'] : '';
			$handles     = isset( $entry['scripts'] ) && is_array( $entry['scripts'] ) ? $entry['scripts'] : [];

			$scripts = array_map(
				static fn( mixed $h ): array => [
					'handle' => is_string( $h ) ? $h : '',
					'src'    => '',
					'type'   => 'enqueued',
				],
				$handles
			);

			$categories[] = [
				'slug'        => $slug,
				'description' => $description,
				'scripts'     => array_values( $scripts ),
			];
		}

		return new \WP_REST_Response(
			[
				'bannerMessage' => (string) Config::get( 'consent_message' ),
				'policyLink'    => (string) Config::get( 'privacy_policy_url' ),
				'categories'    => $categories,
			],
			200
		);
	}

	public function handleCategoriesPut( \WP_REST_Request $request ): \WP_REST_Response
	{
		$body = (array) $request->get_json_params();

		$banner_message = isset( $body['bannerMessage'] ) ? wp_kses_post( (string) $body['bannerMessage'] ) : '';
		$policy_link    = isset( $body['policyLink'] ) ? esc_url_raw( (string) $body['policyLink'] ) : '';

		$this->mergeConsentOption(
            [
				'consent_message'    => $banner_message,
				'privacy_policy_url' => $policy_link,
            ]
        );

		$valid_slugs = array_map( static fn( Category $c ): string => $c->value, Category::cases() );
		$stored      = [];

		$raw_cats = isset( $body['categories'] ) && is_array( $body['categories'] ) ? $body['categories'] : [];
		foreach ( $raw_cats as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$slug = isset( $cat['slug'] ) && is_string( $cat['slug'] ) ? $cat['slug'] : '';
			if ( ! in_array( $slug, $valid_slugs, true ) ) {
				continue;
			}
			$description     = isset( $cat['description'] ) ? sanitize_textarea_field( (string) $cat['description'] ) : '';
			$raw_scripts     = isset( $cat['scripts'] ) && is_array( $cat['scripts'] ) ? $cat['scripts'] : [];
			// The admin UI sends each script as a {handle, src, type} OBJECT (api/categories.ts
			// Script type); older/direct writers may send a bare string. Extract the handle from
			// either shape, THEN drop blanks — an empty handle can never match an enqueued script
			// and only makes junk rows. (Round-3 F3 added the blank filter but assumed bare
			// strings, which silently blanked EVERY real object-shaped handle → round-4 F1.)
			$handles         = array_values(
				array_filter(
					array_map(
						static function ( mixed $h ): string {
							$handle = is_array( $h ) ? ( $h['handle'] ?? '' ) : $h;
							return sanitize_text_field( is_string( $handle ) ? $handle : '' );
						},
						$raw_scripts
					),
					static fn( string $h ): bool => $h !== ''
				)
			);
			$stored[ $slug ] = [
				'description' => $description,
				'scripts'     => $handles,
			];
		}

		update_option( 'pk_standard_consent_categories', $stored );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	public function handleSettingsGet( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		return new \WP_REST_Response(
			[
				'consent_enabled'     => (bool) Config::get( 'consent_enabled' ),
				'consent_mode_v2'     => (bool) Config::get( 'consent_mode_v2' ),
				'floating_reopen'     => (bool) Config::get( 'floating_reopen' ),
				'geo_header'          => (string) Config::get( 'geo_header' ),
				'geo_state_header'    => (string) Config::get( 'geo_state_header' ),
				'geo_provider_url'    => (string) Config::get( 'geo_provider_url' ),
				'geo_fallback_region' => (string) Config::get( 'geo_fallback_region' ),
				'log_retention_days'  => (int) Config::get( 'log_retention_days' ),
				'uninstall_clean'     => (bool) Config::get( 'uninstall_clean' ),
				'debug_mode'          => (bool) Config::get( 'debug_mode' ),
			],
			200
		);
	}

	public function handleSettingsPut( \WP_REST_Request $request ): \WP_REST_Response
	{
		$body = (array) $request->get_json_params();

		$updates = [];

		if ( array_key_exists( 'consent_enabled', $body ) ) {
			$updates['consent_enabled'] = (bool) $body['consent_enabled'];
		}
		if ( array_key_exists( 'consent_mode_v2', $body ) ) {
			$updates['consent_mode_v2'] = (bool) $body['consent_mode_v2'];
		}
		if ( array_key_exists( 'floating_reopen', $body ) ) {
			$updates['floating_reopen'] = (bool) $body['floating_reopen'];
		}
		if ( array_key_exists( 'geo_header', $body ) ) {
			$updates['geo_header'] = sanitize_text_field( (string) $body['geo_header'] );
		}
		if ( array_key_exists( 'geo_state_header', $body ) ) {
			$updates['geo_state_header'] = sanitize_text_field( (string) $body['geo_state_header'] );
		}
		if ( array_key_exists( 'geo_provider_url', $body ) ) {
			$updates['geo_provider_url'] = $this->sanitizeProviderUrl( (string) $body['geo_provider_url'] );
		}
		if ( array_key_exists( 'geo_fallback_region', $body ) ) {
			$updates['geo_fallback_region'] = sanitize_text_field( (string) $body['geo_fallback_region'] );
		}
		if ( array_key_exists( 'log_retention_days', $body ) ) {
			$updates['log_retention_days'] = (int) $body['log_retention_days'];
		}
		if ( array_key_exists( 'uninstall_clean', $body ) ) {
			$updates['uninstall_clean'] = (bool) $body['uninstall_clean'];
		}
		if ( array_key_exists( 'debug_mode', $body ) ) {
			$updates['debug_mode'] = (bool) $body['debug_mode'];
		}

		$this->mergeConsentOption( $updates );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	public function handleLogDelete( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		global $wpdb;

		$table = $wpdb->prefix . 'pk_sc_consent_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- TRUNCATE has no user-supplied values; table name is $wpdb->prefix + a fixed suffix and cannot use prepare().
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Scoped per-visitor erasure (GDPR Art. 17 request handling; round-5 F3). Takes a log
	 * row id (the list API truncates visitor_hash to 8 chars for display, so the full hash
	 * never leaves the server), resolves that row's visitor_hash, and deletes ONLY that
	 * visitor's rows — before this, the sole delete path was the unscoped whole-table
	 * TRUNCATE above, so fulfilling a single subject's erasure request meant destroying
	 * every other visitor's consent proof.
	 */
	public function handleVisitorErase( \WP_REST_Request $request ): \WP_REST_Response
	{
		global $wpdb;

		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . 'pk_sc_consent_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash = $wpdb->get_var( $wpdb->prepare( "SELECT visitor_hash FROM {$table} WHERE id = %d", $id ) );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return new \WP_REST_Response( [ 'error' => 'row_not_found' ], 404 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, [ 'visitor_hash' => $hash ], [ '%s' ] );

		if ( false === $deleted ) {
			return new \WP_REST_Response( [ 'error' => 'delete_failed' ], 500 );
		}

		return new \WP_REST_Response( [ 'deleted' => (int) $deleted ], 200 );
	}

	/**
	 * Sanitizes the IP-geo provider URL while preserving the literal {ip} placeholder, which
	 * esc_url_raw would otherwise strip. The placeholder is swapped for an URL-safe sentinel,
	 * the URL is normalized, then the placeholder is restored. An empty/invalid URL stores ''.
	 */
	private function sanitizeProviderUrl( string $url ): string
	{
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$sentinel = '0PKSCIP0';
		$safe     = esc_url_raw( str_replace( '{ip}', $sentinel, $url ) );
		return '' === $safe ? '' : str_replace( $sentinel, '{ip}', $safe );
	}

	/**
	 * @param array<string, bool|int|string> $updates
	 */
	private function mergeConsentOption( array $updates ): void
	{
		$stored = get_option( 'pk_standard_consent', [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $updates as $key => $value ) {
			$stored[ $key ] = $value;
		}
		update_option( 'pk_standard_consent', $stored );
	}
}
