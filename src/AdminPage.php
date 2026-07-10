<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AdminPage
{
    private const PAGES = [
        'pk-standard-consent'          => 'Categories & Scripts',
        'pk-standard-consent-scan'     => 'Cookie Scan',
        'pk-standard-consent-regions'  => 'Regions & Rules',
        'pk-standard-consent-log'      => 'Consent Log',
        'pk-standard-consent-settings' => 'Settings',
    ];

    public function register(): void
    {
        add_menu_page(
            'Standard Consent',
            'Standard Consent',
            'manage_options',
            'pk-standard-consent',
            [ $this, 'render' ],
            'dashicons-shield',
            81
        );

        foreach ( self::PAGES as $slug => $label ) {
            add_submenu_page(
                'pk-standard-consent',
                $label,
                $label,
                'manage_options',
                $slug,
                [ $this, 'render' ]
            );
        }
    }

    public function render(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div id="pk-sc-admin-root" class="pk-root"></div>';
    }

    public function enqueue( string $hook ): void
    {
        $allowed = array_map(
            static fn( string $slug ): string => 'pk-standard-consent' === $slug
                ? "toplevel_page_{$slug}"
                : "standard-consent_page_{$slug}",
            array_keys( self::PAGES )
        );
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }

        // Cache-bust admin assets by version + filemtime PER FILE. The old `$ver = defined() ?
        // PK_SC_VERSION : filemtime()` only used filemtime when the constant was undefined (never
        // in prod), so a code change to admin.js/css served stale to returning admins until a
        // manual version bump — the same bug round-1 fixed for FRONTEND assets but missed here
        // (F1, round-2 audit). Matches FrontendAssets::assetVersion.
        $css = PK_SC_DIR . '/assets/dist/admin.css';
        $js  = PK_SC_DIR . '/assets/dist/admin.js';
        $ver = static function ( string $file ): string {
            $base = defined( 'PK_SC_VERSION' ) ? PK_SC_VERSION : '0';
            return is_readable( $file ) ? $base . '.' . filemtime( $file ) : $base;
        };

        // Family stack (same three shared files as every other plugin). Order: tokens ->
        // SPA bundle -> shared components -> shell, so the shared design system wins any
        // stale duplicate compiled into the bundle.
        wp_enqueue_style( 'pk-sc-tokens', plugins_url( 'assets/tokens.css', PK_SC_FILE ), [], $ver( PK_SC_DIR . '/assets/tokens.css' ) );
        $components_deps = [ 'pk-sc-tokens' ];
        if ( file_exists( $css ) ) {
            wp_enqueue_style( 'pk-sc-admin', plugins_url( 'assets/dist/admin.css', PK_SC_FILE ), [ 'pk-sc-tokens' ], $ver( $css ) );
            $components_deps = [ 'pk-sc-admin' ];
        }
        wp_enqueue_style( 'pk-sc-components', plugins_url( 'assets/components.css', PK_SC_FILE ), $components_deps, $ver( PK_SC_DIR . '/assets/components.css' ) );
        wp_enqueue_style( 'pk-sc-shell', plugins_url( 'assets/admin-shell.css', PK_SC_FILE ), [ 'pk-sc-components' ], $ver( PK_SC_DIR . '/assets/admin-shell.css' ) );
        if ( file_exists( $js ) ) {
            wp_enqueue_script( 'pk-sc-admin', plugins_url( 'assets/dist/admin.js', PK_SC_FILE ), [], $ver( $js ), true );
        }

        $raw_page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : 'pk-standard-consent'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page slug is not sensitive; read-only display routing.
        wp_localize_script(
            'pk-sc-admin',
            'PKStandardConsent',
            [
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'restUrl' => rest_url( 'pk-standard-consent/v1/admin/' ),
                'page'    => $raw_page,
            ]
        );
    }
}
