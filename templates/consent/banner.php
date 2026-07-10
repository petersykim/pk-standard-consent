<?php
/**
 * Front-end consent banner.
 *
 * PHP always renders this hidden; consent-banner.js shows it when needed.
 * A full-page cache primed by a consented visitor would otherwise suppress the
 * banner for a fresh visitor — a GDPR failure. JS reads PKStandardConsent.granted
 * and PKStandardConsent.stale to decide whether to reveal the markup.
 *
 * Theme override: drop consent/banner.php in the active theme. Data hooks
 * (data-pk-sc="*" / data-pk-sc-category) must be preserved; class names are optional.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use PK\StandardConsent\Config;
use PK\StandardConsent\Geo;
use PK\StandardConsent\RegionRules;

$group = RegionRules::resolveGroup( Geo::detect() );
$rule  = RegionRules::resolveDefaults( $group );
$model = $rule['model'];

$message    = (string) Config::get( 'consent_message' );
$policy_url = (string) Config::get( 'privacy_policy_url' );
if ( '' === $policy_url ) {
    $policy_url = (string) get_privacy_policy_url();
}
?>
<div class="pk-sc-banner"
     role="dialog"
     aria-modal="true"
     aria-label="<?php echo 'opt-in' === $model ? esc_attr__( 'Cookie consent', 'pk-standard-consent' ) : esc_attr__( 'Privacy preferences', 'pk-standard-consent' ); ?>"
     aria-describedby="pk-sc-banner-desc"
     data-pk-sc="banner"
     hidden>

<?php if ( 'opt-in' === $model ) : ?>
    <div class="pk-sc-banner__inner">
        <div class="pk-sc-banner__copy" id="pk-sc-banner-desc">
            <p class="pk-sc-banner__heading"><?php esc_html_e( 'We use cookies', 'pk-standard-consent' ); ?></p>
            <p class="pk-sc-banner__message">
                <?php
                if ( '' !== $message ) {
                    echo wp_kses_post( $message );
                } else {
                    esc_html_e( 'We use cookies to improve your experience, remember your preferences, and understand how you use our site. You can accept all, reject non-essential cookies, or choose what you allow.', 'pk-standard-consent' );
                }
                ?>
                <?php if ( '' !== $policy_url ) : ?>
                    <a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Cookie policy', 'pk-standard-consent' ); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <div class="pk-sc-banner__actions">
            <button class="pk-sc-btn pk-sc-btn--ghost pk-sc-btn--sm" type="button" data-pk-sc="open-prefs">
                <?php esc_html_e( 'Manage preferences', 'pk-standard-consent' ); ?>
            </button>
            <button class="pk-sc-btn pk-sc-btn--secondary pk-sc-btn--sm" type="button" data-pk-sc="reject-all">
                <?php esc_html_e( 'Reject all', 'pk-standard-consent' ); ?>
            </button>
            <button class="pk-sc-btn pk-sc-btn--primary" type="button" data-pk-sc="accept-all">
                <?php esc_html_e( 'Accept all', 'pk-standard-consent' ); ?>
            </button>
        </div>
    </div>

<?php else : ?>
    <div class="pk-sc-banner__inner">
        <div class="pk-sc-banner__copy" id="pk-sc-banner-desc">
            <p class="pk-sc-banner__heading"><?php esc_html_e( 'Your privacy choices', 'pk-standard-consent' ); ?></p>
            <p class="pk-sc-banner__message">
                <?php
                if ( '' !== $message ) {
                    echo wp_kses_post( $message );
                } else {
                    esc_html_e( 'We use cookies and similar technologies to analyze site usage and deliver personalized advertising. As a California resident, you have the right to opt out of the sale or sharing of your personal information.', 'pk-standard-consent' );
                }
                ?>
                <?php if ( '' !== $policy_url ) : ?>
                    <a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Privacy policy', 'pk-standard-consent' ); ?></a>
                <?php endif; ?>
            </p>

            <div class="pk-sc-optout-toggles">
                <label class="pk-sc-optout-toggle">
                    <input type="checkbox"
                           class="pk-sc-switch"
                           checked
                           data-pk-sc-category="analytics"
                           aria-label="<?php esc_attr_e( 'Analytics cookies', 'pk-standard-consent' ); ?>">
                    <?php esc_html_e( 'Analytics', 'pk-standard-consent' ); ?>
                </label>
                <label class="pk-sc-optout-toggle">
                    <input type="checkbox"
                           class="pk-sc-switch"
                           checked
                           data-pk-sc-category="marketing"
                           aria-label="<?php esc_attr_e( 'Marketing and advertising cookies', 'pk-standard-consent' ); ?>">
                    <?php esc_html_e( 'Marketing &amp; advertising', 'pk-standard-consent' ); ?>
                </label>
            </div>

            <div class="pk-sc-banner__actions">
                <button class="pk-sc-btn pk-sc-btn--primary" type="button" data-pk-sc="save-optout">
                    <?php esc_html_e( 'Save my choices', 'pk-standard-consent' ); ?>
                </button>
                <button class="pk-sc-banner__dnsas" type="button" data-pk-sc="dnsas">
                    <?php esc_html_e( 'Do Not Sell or Share My Personal Information', 'pk-standard-consent' ); ?>
                </button>
            </div>
        </div>

        <div class="pk-sc-banner__right">
            <button class="pk-sc-btn pk-sc-btn--ghost pk-sc-btn--sm"
                    type="button"
                    data-pk-sc="continue"
                    aria-label="<?php esc_attr_e( 'Close and continue with current settings', 'pk-standard-consent' ); ?>">
                <?php esc_html_e( 'Continue without changing', 'pk-standard-consent' ); ?>
            </button>
        </div>
    </div>
<?php endif; ?>

</div>
