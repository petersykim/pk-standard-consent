<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScanRest
{
	use AdminAuth;

	private const NS = 'pk-standard-consent/v1';

	// Transient TTLs (seconds). RUNNING is short so a stalled scan self-clears;
	// DONE persists the last result long enough for the admin UI to read it.
	private const STATUS_TTL_RUNNING = 300;
	private const STATUS_TTL_DONE    = 3600;

	public function register(): void
	{
		register_rest_route(
			self::NS,
			'/admin/scan',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleScan' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/scan/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleStatus' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/scan/results',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleResults' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/scan/override',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'handleOverride' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/scan/config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handleConfigGet' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);

		register_rest_route(
			self::NS,
			'/admin/scan/config',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'handleConfigPut' ],
				'permission_callback' => [ $this, 'verifyAdminNonce' ],
			]
		);
	}

	public function handleConfigGet( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		return new \WP_REST_Response(
			[
				'scan_urls'            => (string) Config::get( 'scan_urls' ),
				'scan_url_limit'       => (int) Config::get( 'scan_url_limit' ),
				'scan_request_timeout' => (int) Config::get( 'scan_request_timeout' ),
				'scan_wall_limit'      => (int) Config::get( 'scan_wall_limit' ),
				'scan_sslverify'       => (bool) Config::get( 'scan_sslverify' ),
			],
			200
		);
	}

	public function handleConfigPut( \WP_REST_Request $request ): \WP_REST_Response
	{
		$body    = (array) $request->get_json_params();
		$updates = [];

		if ( array_key_exists( 'scan_urls', $body ) ) {
			$updates['scan_urls'] = sanitize_textarea_field( (string) $body['scan_urls'] );
		}
		// Limits are clamped so an admin can't set a 0/negative or a runaway crawl.
		if ( array_key_exists( 'scan_url_limit', $body ) ) {
			$updates['scan_url_limit'] = max( 1, min( 100, (int) $body['scan_url_limit'] ) );
		}
		if ( array_key_exists( 'scan_request_timeout', $body ) ) {
			$updates['scan_request_timeout'] = max( 1, min( 60, (int) $body['scan_request_timeout'] ) );
		}
		if ( array_key_exists( 'scan_wall_limit', $body ) ) {
			$updates['scan_wall_limit'] = max( 5, min( 300, (int) $body['scan_wall_limit'] ) );
		}
		if ( array_key_exists( 'scan_sslverify', $body ) ) {
			$updates['scan_sslverify'] = (bool) $body['scan_sslverify'];
		}

		$stored = get_option( 'pk_standard_consent', [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( $updates as $key => $value ) {
			$stored[ $key ] = $value;
		}
		update_option( 'pk_standard_consent', $stored );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	public function handleScan( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		set_transient(
			'pk_sc_scan_status',
			[
				'status'     => 'running',
				'started_at' => time(),
			],
			self::STATUS_TTL_RUNNING
		);

		$scanner = new Scanner();
		$rows    = $scanner->run();
		$results = new ScanResults();
		$results->upsert( $rows );

		$flagged = count( array_filter( $rows, static fn( $r ) => 1 === $r['compliance_flag'] ) );
		// 'blocked' outranks 'partial'/'done': if the crawler hit an auth wall, the row set is
		// unreliable and a low/zero count is meaningless (round-4 audit F2).
		if ( $scanner->blocked() ) {
			$status = 'blocked';
		} elseif ( $scanner->timedOut() ) {
			$status = 'partial';
		} else {
			$status = 'done';
		}

		set_transient(
			'pk_sc_scan_status',
			[
				'status'  => $status,
				'count'   => count( $rows ),
				'flagged' => $flagged,
			],
			self::STATUS_TTL_DONE
		);

		return new \WP_REST_Response(
			[
				'status'  => $status,
				'results' => $rows,
				'count'   => count( $rows ),
				'flagged' => $flagged,
			],
			200
		);
	}

	public function handleStatus( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		$scan_status = get_transient( 'pk_sc_scan_status' );
		if ( false === $scan_status ) {
			$scan_status = [ 'status' => 'idle' ];
		}
		return new \WP_REST_Response( $scan_status, 200 );
	}

	public function handleResults( \WP_REST_Request $request ): \WP_REST_Response // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API requires the $request signature.
	{
		$results = new ScanResults();
		return new \WP_REST_Response( $results->loadAll(), 200 );
	}

	public function handleOverride( \WP_REST_Request $request ): \WP_REST_Response
	{
		/** @var mixed $raw_cookie */
		$raw_cookie = $request->get_param( 'cookie_name' );
		/** @var mixed $raw_cat */
		$raw_cat     = $request->get_param( 'category' );
		$cookie_name = sanitize_text_field( is_string( $raw_cookie ) ? $raw_cookie : '' );
		$category    = sanitize_text_field( is_string( $raw_cat ) ? $raw_cat : '' );

		if ( '' === $cookie_name || '' === $category ) {
			return new \WP_REST_Response( [ 'error' => 'Missing parameters' ], 400 );
		}

		$results = new ScanResults();
		if ( ! $results->setOverride( $cookie_name, $category ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_category' ], 400 );
		}

		return new \WP_REST_Response( [ 'updated' => true ], 200 );
	}
}
