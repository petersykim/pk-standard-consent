<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        self::$instance = new self();
        register_activation_hook( PK_SC_FILE, [ self::class, 'onActivate' ] );
        register_deactivation_hook( PK_SC_FILE, [ self::class, 'onDeactivate' ] );
        self::$instance->wireHooks();
    }

    public static function onActivate(): void
    {
        Schema::createTable();
        Schema::createScanTable();
        update_option( 'pk_sc_db_version', PK_SC_VERSION );

        // Safer default posture (C7): turn the gate ON on first activation so a fresh install
        // is not silently ungated ("don't get sued"). Only seed it when the admin has never
        // saved a consent option, so a deliberate later opt-out is never overridden on reactivation.
        // A one-time admin notice then prompts the admin to configure categories.
        if ( false === get_option( 'pk_standard_consent', false ) ) {
            update_option( 'pk_standard_consent', [ 'consent_enabled' => true ] );
            update_option( 'pk_sc_activation_notice', '1' );
        }
    }

    public static function onDeactivate(): void
    {
        wp_clear_scheduled_hook( 'pk_sc_log_cleanup' );
    }

    /**
     * Runs dbDelta when the stored DB version trails PK_SC_VERSION, so schema additions
     * (e.g. the consent_method / policy_hash columns) land on a plugin update without a
     * manual reactivation. dbDelta is idempotent — adds missing columns, never drops data.
     */
    public static function maybeUpgrade(): void
    {
        if ( get_option( 'pk_sc_db_version' ) === PK_SC_VERSION ) {
            return;
        }
        Schema::createTable();
        Schema::createScanTable();
        update_option( 'pk_sc_db_version', PK_SC_VERSION );
    }

    private function wireHooks(): void
    {
        add_action( 'plugins_loaded', [ self::class, 'maybeUpgrade' ] );

        // The REST engine runs whenever the plugin is active, independent of the theme-side flag.
        add_action( 'rest_api_init', [ new Rest(), 'register' ] );
        add_action( 'rest_api_init', [ new ScanRest(), 'register' ] );
        add_action( 'rest_api_init', [ new LogRest(), 'register' ] );
        add_action( 'rest_api_init', [ new AdminRest(), 'register' ] );
        add_action( 'pk_sc_log_cleanup', [ LogRest::class, 'cleanup' ] );

        $admin_page = new AdminPage();
        add_action( 'admin_menu', [ $admin_page, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ $admin_page, 'enqueue' ] );
        add_action( 'admin_notices', [ self::class, 'maybeActivationNotice' ] );
        if ( ! wp_next_scheduled( 'pk_sc_log_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'pk_sc_log_cleanup' );
        }

        // Front-end gating is theme-side: it fires only when a theme opts in via consent_enabled.
        if ( ! (bool) Config::get( 'consent_enabled' ) ) {
            return;
        }

        add_action( 'wp_head', [ new ConsentMode(), 'emitConsentDefault' ], 1 );
        add_action( 'wp_head', [ new ConsentMode(), 'emitGlobal' ], 2 );
        // Bridge admin-configured category->handle map into the registry BEFORE the
        // priority-0 wp_print_scripts dequeue, so UI-assigned scripts actually gate.
        add_action( 'wp_enqueue_scripts', [ new GateBridge(), 'registerConfiguredScripts' ], 99 );
        add_action( 'wp_print_scripts', [ ScriptRegistry::instance(), 'blockUnconsented' ], 0 );
        // Page-wide buffer that neutralizes inline / GTM / theme-header / head-injected
        // <script> tags by src-pattern or data-pk-sc-category (not just enqueued handles).
        add_action( 'template_redirect', [ new TagRewriter(), 'start' ], 0 );
        add_action( 'wp_footer', [ ScriptRegistry::instance(), 'emitBlocked' ], 0 );
        add_action( 'wp_enqueue_scripts', [ new FrontendAssets(), 'enqueue' ] );
        add_action( 'wp_footer', [ $this, 'renderTemplates' ], 99 );
    }

    /**
     * One-time post-activation notice (C7): tells the admin the gate is ON and prompts them
     * to configure script categories. Self-dismisses (deletes the flag) after one render so it
     * never nags. manage_options-gated; output is escaped.
     */
    public static function maybeActivationNotice(): void
    {
        $notice_flag = get_option( 'pk_sc_activation_notice', '' );
        if ( ! current_user_can( 'manage_options' ) || '1' !== ( is_scalar( $notice_flag ) ? (string) $notice_flag : '' ) ) {
            return;
        }
        delete_option( 'pk_sc_activation_notice' );

        $url = admin_url( 'admin.php?page=pk-standard-consent' );
        printf(
            '<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'Standard Consent is active and the consent gate is ON. Assign your analytics and marketing scripts to categories so they are blocked until a visitor consents.', 'pk-standard-consent' ),
            esc_url( $url ),
            esc_html__( 'Configure categories', 'pk-standard-consent' )
        );
    }

    public function renderTemplates(): void
    {
        load_template( pk_sc_locate_template( 'banner' ), false );
        load_template( pk_sc_locate_template( 'preferences' ), false );
        load_template( pk_sc_locate_template( 'reopen' ), false );
    }
}
