<?php
/**
 * Front-end preference center.
 *
 * One row per Category enum case. JS populates descriptions and cookie table
 * rows from PKStandardConsent.categories (no async fetch needed). The expand
 * button is hidden when no category data is available, rather than showing an
 * empty table.
 *
 * Theme override: drop consent/preferences.php in the active theme.
 * Keep all data-pk-sc* attributes — the controller binds only to those.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use PK\StandardConsent\Category;
use PK\StandardConsent\Config;

$policy_url = (string) Config::get( 'privacy_policy_url' );
if ( '' === $policy_url ) {
    $policy_url = (string) get_privacy_policy_url();
}
?>
<div class="pk-sc-prefs-overlay" data-pk-sc="prefs-overlay" aria-hidden="true" hidden></div>

<div class="pk-sc-prefs"
     role="dialog"
     aria-modal="true"
     aria-label="<?php esc_attr_e( 'Cookie preferences', 'pk-standard-consent' ); ?>"
     aria-describedby="pk-sc-prefs-desc"
     data-pk-sc="prefs"
     hidden>

    <div class="pk-sc-prefs__header">
        <button class="pk-sc-prefs__close"
                type="button"
                data-pk-sc="close-prefs"
                aria-label="<?php esc_attr_e( 'Close cookie preferences', 'pk-standard-consent' ); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:16px;height:16px;stroke:currentColor;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <p class="pk-sc-prefs__title"><?php esc_html_e( 'Cookie preferences', 'pk-standard-consent' ); ?></p>
        <p class="pk-sc-prefs__intro" id="pk-sc-prefs-desc">
            <?php esc_html_e( 'Choose which cookies you allow. Necessary cookies keep the site working and cannot be disabled.', 'pk-standard-consent' ); ?>
            <?php if ( '' !== $policy_url ) : ?>
                <a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Cookie policy', 'pk-standard-consent' ); ?></a>
            <?php endif; ?>
        </p>
    </div>

    <div class="pk-sc-prefs__body">
        <?php foreach ( Category::cases() as $category ) : ?>
            <?php $slug = $category->value; ?>
            <div class="pk-sc-cat" data-pk-sc-cat="<?php echo esc_attr( $slug ); ?>">
                <div class="pk-sc-cat__row">
                    <div class="pk-sc-cat__info">
                        <div class="pk-sc-cat__name">
                            <?php echo esc_html( ucfirst( $slug ) ); ?>
                            <?php if ( Category::Necessary === $category ) : ?>
                                <span class="pk-sc-badge pk-sc-badge--success"><?php esc_html_e( 'Required', 'pk-standard-consent' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="pk-sc-cat__desc" data-pk-sc-desc="<?php echo esc_attr( $slug ); ?>"></p>
                        <button class="pk-sc-cat__expand"
                                type="button"
                                aria-expanded="false"
                                aria-controls="pk-sc-cookies-<?php echo esc_attr( $slug ); ?>"
                                data-pk-sc="expand"
                                data-pk-sc-for="<?php echo esc_attr( $slug ); ?>"
                                hidden>
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" style="width:12px;height:12px;stroke:currentColor;"><polyline points="6 9 12 15 18 9"/></svg>
                            <span data-pk-sc-expand-label></span>
                        </button>
                    </div>
                    <div class="pk-sc-cat__control">
                        <?php if ( Category::Necessary === $category ) : ?>
                            <input type="checkbox"
                                   class="pk-sc-switch"
                                   checked
                                   disabled
                                   aria-label="<?php esc_attr_e( 'Necessary cookies — always active', 'pk-standard-consent' ); ?>">
                            <span class="pk-sc-always-active"><?php esc_html_e( 'Always active', 'pk-standard-consent' ); ?></span>
                        <?php else : ?>
                            <?php
                            /* translators: %s: category name */
                            $allow_label = sprintf( esc_attr__( 'Allow %s cookies', 'pk-standard-consent' ), esc_attr( $slug ) );
                            ?>
                            <input type="checkbox"
                                   class="pk-sc-switch"
                                   data-pk-sc-category="<?php echo esc_attr( $slug ); ?>"
                                   aria-label="<?php echo esc_attr( $allow_label ); ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                /* translators: %s: category name */
                $table_label = sprintf( esc_attr__( '%s cookies', 'pk-standard-consent' ), esc_attr( ucfirst( $slug ) ) );
                ?>
                <table class="pk-sc-cookie-table"
                       id="pk-sc-cookies-<?php echo esc_attr( $slug ); ?>"
                       aria-label="<?php echo esc_attr( $table_label ); ?>"
                       hidden>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Cookie', 'pk-standard-consent' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Provider', 'pk-standard-consent' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Purpose', 'pk-standard-consent' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Duration', 'pk-standard-consent' ); ?></th>
                        </tr>
                    </thead>
                    <tbody data-pk-sc-cookie-rows="<?php echo esc_attr( $slug ); ?>"></tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="pk-sc-prefs__footer">
        <div class="pk-sc-prefs__footer-left">
            <?php if ( '' !== $policy_url ) : ?>
                <a href="<?php echo esc_url( $policy_url ); ?>"><?php esc_html_e( 'Privacy &amp; cookie policy', 'pk-standard-consent' ); ?></a>
            <?php endif; ?>
        </div>
        <div class="pk-sc-prefs__footer-actions">
            <button class="pk-sc-btn pk-sc-btn--ghost pk-sc-btn--sm" type="button" data-pk-sc="reject-all">
                <?php esc_html_e( 'Reject all', 'pk-standard-consent' ); ?>
            </button>
            <button class="pk-sc-btn pk-sc-btn--secondary pk-sc-btn--sm" type="button" data-pk-sc="accept-all">
                <?php esc_html_e( 'Accept all', 'pk-standard-consent' ); ?>
            </button>
            <button class="pk-sc-btn pk-sc-btn--primary" type="button" data-pk-sc="save-prefs">
                <?php esc_html_e( 'Save preferences', 'pk-standard-consent' ); ?>
            </button>
        </div>
    </div>

</div>
