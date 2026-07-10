<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FrontendAssets
{
    public function enqueue(): void
    {
        wp_enqueue_script(
            'pk-sc-gating',
            plugins_url( 'assets/consent-gate.js', PK_SC_FILE ),
            [],
            self::assetVersion( 'assets/consent-gate.js' ),
            true
        );

        wp_enqueue_style(
            'pk-sc-consent',
            plugins_url( 'assets/consent.css', PK_SC_FILE ),
            [],
            self::assetVersion( 'assets/consent.css' )
        );

        wp_enqueue_script(
            'pk-sc-banner',
            plugins_url( 'assets/consent-banner.js', PK_SC_FILE ),
            [ 'pk-sc-gating' ],
            self::assetVersion( 'assets/consent-banner.js' ),
            true
        );
    }

    /**
     * Cache-bust frontend assets by filemtime so a code change to a JS/CSS file is served
     * fresh WITHOUT bumping PK_SC_VERSION — a static version served stale assets to returning
     * visitors after every code edit (F2, round-1 audit). Mirrors AdminPage's filemtime use.
     */
    private static function assetVersion( string $relPath ): string
    {
        $file = plugin_dir_path( PK_SC_FILE ) . $relPath;
        $mtime = is_readable( $file ) ? (string) filemtime( $file ) : '';
        return $mtime !== '' ? PK_SC_VERSION . '.' . $mtime : PK_SC_VERSION;
    }
}
