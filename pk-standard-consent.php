<?php
/**
 * Plugin Name: Standard Consent
 * Plugin URI:  https://github.com/peterkim
 * Description: Consent management — replaces CookieYes / OneTrust / Complianz.
 * Version:     0.2.9
 * Author:      Peter Kim
 * Author URI:  https://github.com/peterkim
 * Text Domain: pk-standard-consent
 * Requires PHP: 8.2
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PK_SC_VERSION', '0.2.9' );
define( 'PK_SC_DIR', __DIR__ );
define( 'PK_SC_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

PK\StandardConsent\Plugin::boot();

function pk_sc_config( string $key ): bool|string|int {
    return PK\StandardConsent\Config::get( $key );
}

function pk_sc_register_script( string $handle, PK\StandardConsent\Category $category, string $src ): void {
    PK\StandardConsent\ScriptRegistry::instance()->register( $handle, $category, $src );
}

function pk_sc_locate_template( string $name ): string {
    $stylesheet = get_stylesheet_directory() . '/consent/' . $name . '.php';
    $template   = get_template_directory() . '/consent/' . $name . '.php';
    $plugin     = PK_SC_DIR . '/templates/consent/' . $name . '.php';

    if ( file_exists( $stylesheet ) ) {
        return $stylesheet;
    }

    if ( get_stylesheet_directory() !== get_template_directory() && file_exists( $template ) ) {
        return $template;
    }

    if ( file_exists( $plugin ) ) {
        return $plugin;
    }

    return $plugin;
}
