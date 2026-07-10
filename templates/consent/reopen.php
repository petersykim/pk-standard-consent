<?php
/**
 * Reopen trigger rendered in the footer.
 *
 * Shown by JS (showReopen) after any consent action so the visitor can
 * always revisit their choices without hunting for a link.
 *
 * The floating button is an optional enhancement gated on Config('floating_reopen')
 * so themes that prefer a footer-only trigger can leave it off.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use PK\StandardConsent\Config;
?>
<div aria-live="polite" aria-atomic="true">
    <button class="pk-sc-reopen"
            type="button"
            data-pk-sc="reopen-btn"
            hidden>
        <?php esc_html_e( 'Cookie preferences', 'pk-standard-consent' ); ?>
    </button>
</div>

<?php if ( (bool) Config::get( 'floating_reopen' ) ) : ?>
    <button class="pk-sc-reopen-float"
            type="button"
            data-pk-sc="reopen-float"
            aria-label="<?php esc_attr_e( 'Reopen cookie preferences', 'pk-standard-consent' ); ?>"
            hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:20px;height:20px;stroke:currentColor;"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
    </button>
<?php endif; ?>
