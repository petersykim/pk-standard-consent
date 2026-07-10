<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bridges the admin-configured category -> script-handle map (saved to the
 * pk_standard_consent_categories option by the Categories & Scripts screen)
 * into the runtime gate.
 *
 * Without this, the gate only blocked handles a developer registered in PHP via
 * pk_sc_register_script(); an admin who assigned handles in the UI had a banner
 * that blocked nothing. This reads the saved map on wp_enqueue_scripts and calls
 * ScriptRegistry::register() for each handle in a gateable category, so the
 * priority-0 wp_print_scripts dequeue (ScriptRegistry::blockUnconsented) sees them.
 */
final class GateBridge
{
    /**
     * Registers every admin-assigned handle in a gateable category.
     * Runs on wp_enqueue_scripts (default priority) so it lands before the
     * priority-0 wp_print_scripts pass that does the actual dequeue.
     */
    public function registerConfiguredScripts(): void
    {
        $stored = get_option( 'pk_standard_consent_categories', [] );
        if ( ! is_array( $stored ) ) {
            return;
        }

        $registry = ScriptRegistry::instance();

        foreach ( $stored as $slug => $entry ) {
            $category = $this->gateableCategory( is_string( $slug ) ? $slug : '' );
            if ( null === $category || ! is_array( $entry ) ) {
                continue;
            }

            $handles = isset( $entry['scripts'] ) && is_array( $entry['scripts'] ) ? $entry['scripts'] : [];
            foreach ( $handles as $handle ) {
                if ( is_string( $handle ) && '' !== $handle ) {
                    // Empty src: blockUnconsented resolves the real src from $wp_scripts at dequeue time.
                    $registry->register( $handle, $category, '' );
                }
            }
        }
    }

    /**
     * Resolves a slug to a gateable Category, or null when the slug is unknown
     * or non-gateable (necessary is never blocked).
     */
    private function gateableCategory( string $slug ): ?Category
    {
        $category = Category::tryFrom( $slug );
        if ( null === $category || ! $category->isGateable() ) {
            return null;
        }
        return $category;
    }
}
