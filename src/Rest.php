<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest
{
    private const NS = 'pk-standard-consent/v1';

    public function register(): void
    {
        register_rest_route(
            self::NS,
            '/config',
            [
				'methods'             => 'GET',
				'callback'            => [ $this, 'getConfig' ],
				'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NS,
            '/consent',
            [
				'methods'             => 'POST',
				'callback'            => [ $this, 'postConsent' ],
				'permission_callback' => [ $this, 'verifyNonce' ],
            ]
        );
    }

    public function verifyNonce( \WP_REST_Request $request ): bool
    {
        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
    }

    public function getConfig(): \WP_REST_Response
    {
        $categories = array_map(
            static fn( Category $c ) => $c->value,
            Category::cases()
        );

        $group = RegionRules::resolveGroup( Geo::detect() );
        $rule  = RegionRules::resolveDefaults( $group );

        return new \WP_REST_Response(
            [
				'categories'         => $categories,
				'defaults'           => [
					'preferences' => $rule['preferences'],
					'analytics'   => $rule['analytics'],
					'marketing'   => $rule['marketing'],
				],
				'region'             => $group,
				'model'              => $rule['model'],
				'message'            => (string) Config::get( 'consent_message' ),
				'policy_version'     => (string) Config::get( 'policy_version' ),
				'reconsent_interval' => (int) Config::get( 'reconsent_interval' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    // The consent action the visitor took, for the audit record (D-consent-proof).
    private const METHODS = [ 'accept_all', 'reject_all', 'custom', 'do_not_sell' ];

    // A real visitor writes a handful of rows (accept, then maybe a few preference
    // changes); the window only exists to stop unbounded log flooding (round-5 F1).
    private const RATE_MAX    = 10;
    private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

    public function postConsent( \WP_REST_Request $request ): \WP_REST_Response
    {
        if ( $this->isRateLimited() ) {
            return new \WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
        }

        $body    = (array) $request->get_json_params();
        $cats    = isset( $body['categories'] ) && is_array( $body['categories'] ) ? $body['categories'] : [];
        $version = sanitize_text_field( (string) ( $body['version'] ?? '' ) );
        // mb_substr, NOT substr: a byte-truncation can split a multibyte char at the 8-byte
        // boundary, producing invalid UTF-8 that $wpdb->insert rejects — the GDPR consent row
        // is then LOST while the visitor still gets {ok:true} + a valid cookie. (Region codes
        // are ASCII in practice, but this is a consent-proof audit row; don't risk it.)
        $region  = mb_substr( sanitize_text_field( (string) ( $body['region'] ?? '' ) ), 0, 8 );
        $method  = $this->sanitizeMethod( (string) ( $body['method'] ?? '' ) );

        if ( (string) Config::get( 'policy_version' ) !== $version ) {
            return new \WP_REST_Response( [ 'error' => 'version_mismatch' ], 409 );
        }

        $grants = $this->sanitizeGrants( $cats );

        ConsentLog::insert( $grants, $region, $version, $method, $this->policyHash( $version ) );
        Cookie::write( $grants, $version );

        return new \WP_REST_Response( [ 'ok' => true ] );
    }

    /**
     * Per-IP sliding-window throttle. The /config nonce is public (the banner needs it),
     * so without this any visitor could grow the consent log unboundedly (round-5 F1).
     * The IP is HMAC-hashed so no raw address is stored in the transient key.
     */
    private function isRateLimited(): bool
    {
        // Geo::clientIp() prefers CF-Connecting-IP so distinct visitors behind one Cloudflare
        // PoP get distinct rate buckets, rather than sharing REMOTE_ADDR (the CF edge IP).
        $ip    = Geo::clientIp();
        $key   = 'pk_sc_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_MAX ) {
            return true;
        }
        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return false;
    }

    /** Whitelists the consent method; unknown values are stored as the inferred 'custom'. */
    private function sanitizeMethod( string $method ): string
    {
        return in_array( $method, self::METHODS, true ) ? $method : 'custom';
    }

    /**
     * SHA-256 of the exact policy text the visitor was shown: the banner message, the
     * policy URL, and the policy version. Stored on the log row so an auditor can prove
     * which policy a given consent was given against (GDPR Art. 7(1)).
     */
    private function policyHash( string $version ): string
    {
        $presented = implode(
            '|',
            [
                (string) Config::get( 'consent_message' ),
                (string) Config::get( 'privacy_policy_url' ),
                $version,
            ]
        );
        return hash( 'sha256', $presented );
    }

    /**
     * @param array<mixed> $cats
     * @return array<string, bool>
     */
    private function sanitizeGrants( array $cats ): array
    {
        return [
            'preferences' => (bool) ( $cats['preferences'] ?? false ),
            'analytics'   => (bool) ( $cats['analytics'] ?? false ),
            'marketing'   => (bool) ( $cats['marketing'] ?? false ),
        ];
    }
}
