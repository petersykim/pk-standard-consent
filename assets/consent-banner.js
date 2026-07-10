/* global window, document */
(function () {
    'use strict';

    const cfg = window.PKStandardConsent;
    if (!cfg) {
        return;
    }

    // Focus return targets — stored when we open each layer.
    let bannerReturn = null;
    let prefsReturn  = null;
    let bannerEl     = null;
    let prefsEl      = null;
    let backdropEl   = null;

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function byAttr(val, root) {
        return qs('[data-pk-sc="' + val + '"]', root);
    }

    function allByAttr(val) {
        return qsa('[data-pk-sc="' + val + '"]');
    }

    // ============================================================
    // Focus trap helpers
    // ============================================================
    function focusableIn(el) {
        const nodes = qsa(
            'a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])',
            el
        );
        return nodes.filter(function (n) {
            return !n.hidden && !n.closest('[hidden]');
        });
    }

    function trapFocus(el, evt) {
        const nodes = focusableIn(el);
        if (!nodes.length) {
            return;
        }
        const first = nodes[0];
        const last  = nodes[nodes.length - 1];
        if (evt.shiftKey && document.activeElement === first) {
            evt.preventDefault();
            last.focus();
        } else if (!evt.shiftKey && document.activeElement === last) {
            evt.preventDefault();
            first.focus();
        }
    }

    function focusFirst(el) {
        const nodes = focusableIn(el);
        if (nodes.length) {
            nodes[0].focus();
        }
    }

    // ============================================================
    // Banner show / hide
    // ============================================================
    function onBannerKey(evt) {
        if (evt.key === 'Tab') {
            trapFocus(bannerEl, evt);
        }
        // Esc: opt-out model = handleContinue; opt-in banner does nothing —
        // GDPR requires an explicit choice, so Esc must not imply consent.
        if (evt.key === 'Escape' && cfg.model !== 'opt-in') {
            handleContinue();
        }
    }

    // B-6: dim the page behind the opt-in banner (the CSS + mockup define
    // .pk-sc-banner-backdrop; nothing rendered it). Opt-out keeps the page interactive.
    function showBackdrop() {
        if (cfg.model === 'opt-in' && !backdropEl) {
            backdropEl = document.createElement('div');
            backdropEl.className = 'pk-sc-banner-backdrop';
            document.body.appendChild(backdropEl);
        }
    }

    function hideBackdrop() {
        if (backdropEl && backdropEl.parentNode) {
            backdropEl.parentNode.removeChild(backdropEl);
        }
        backdropEl = null;
    }

    function showBanner() {
        if (!bannerEl) {
            return;
        }
        bannerEl.removeAttribute('hidden');
        // Only the opt-in (GDPR) banner is modal; lock body scroll to match the
        // dimmed backdrop. Opt-out (CCPA) is non-modal and must keep the page
        // scrollable — same gating as showBackdrop() above.
        if (cfg.model === 'opt-in') {
            document.body.style.overflow = 'hidden';
        }
        showBackdrop();
        bannerReturn = document.activeElement;
        focusFirst(bannerEl);
        bannerEl.addEventListener('keydown', onBannerKey);
    }

    function hideBanner() {
        if (!bannerEl) {
            return;
        }
        bannerEl.setAttribute('hidden', '');
        document.body.style.overflow = '';
        hideBackdrop();
        bannerEl.removeEventListener('keydown', onBannerKey);
        if (bannerReturn && bannerReturn.focus) {
            bannerReturn.focus();
        }
        bannerReturn = null;
    }

    // ============================================================
    // Preference center show / hide
    // ============================================================
    function onPrefsKey(evt) {
        if (evt.key === 'Tab') {
            trapFocus(prefsEl, evt);
        }
        if (evt.key === 'Escape') {
            closePrefs();
        }
    }

    function openPrefs() {
        if (!prefsEl) {
            return;
        }
        prefsEl.removeAttribute('hidden');
        const overlay = byAttr('prefs-overlay');
        if (overlay) {
            overlay.removeAttribute('hidden');
            overlay.removeAttribute('aria-hidden');
        }
        prefsReturn = document.activeElement;
        populatePrefs();
        focusFirst(prefsEl);
        prefsEl.addEventListener('keydown', onPrefsKey);
    }

    function closePrefs() {
        if (!prefsEl) {
            return;
        }
        prefsEl.setAttribute('hidden', '');
        const overlay = byAttr('prefs-overlay');
        if (overlay) {
            overlay.setAttribute('hidden', '');
            overlay.setAttribute('aria-hidden', 'true');
        }
        prefsEl.removeEventListener('keydown', onPrefsKey);
        if (prefsReturn && prefsReturn.focus) {
            prefsReturn.focus();
        }
        prefsReturn = null;
    }

    // ============================================================
    // Populate prefs from PKStandardConsent.categories
    // ============================================================
    function populateCookieRows(slug, cookies) {
        const tbody = qs('[data-pk-sc-cookie-rows="' + slug + '"]');
        if (!tbody) {
            return;
        }
        tbody.textContent = '';
        cookies.forEach(function (c) {
            const tr     = document.createElement('tr');
            const fields = [c.name, c.provider, c.purpose, c.duration];
            fields.forEach(function (val) {
                const td = document.createElement('td');
                td.textContent = val || '';
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    function populateCatRow(slug, catData) {
        const descEl = qs('[data-pk-sc-desc="' + slug + '"]');
        if (descEl && catData.description) {
            descEl.textContent = catData.description;
        }
        const cookies   = catData.cookies;
        const expandBtn = qs('[data-pk-sc-for="' + slug + '"]');
        if (!cookies || !cookies.length) {
            return;
        }
        if (expandBtn) {
            expandBtn.removeAttribute('hidden');
            const labelEl = expandBtn.querySelector('[data-pk-sc-expand-label]');
            if (labelEl) {
                const n = cookies.length;
                labelEl.textContent = 'Show ' + n + ' cookie' + (n !== 1 ? 's' : '');
            }
        }
        populateCookieRows(slug, cookies);
    }

    function populatePrefs() {
        const cats = cfg.categories;
        if (!cats) {
            return;
        }
        const granted = cfg.granted || {};
        Object.keys(cats).forEach(function (slug) {
            populateCatRow(slug, cats[slug]);
            // CO-P-01: restore each non-essential toggle to the visitor's SAVED state.
            // Without this, reopening the preference center showed every category OFF
            // regardless of saved consent — so re-saving silently revoked prior grants.
            // 'necessary' has no toggle (rendered as an always-on indicator), so it's a no-op.
            const toggle = qs('[data-pk-sc-category="' + slug + '"]', prefsEl);
            if (toggle) {
                toggle.checked = Boolean(granted[slug]);
            }
        });
    }

    // ============================================================
    // Consent actions
    // ============================================================
    function buildGrants(preferences, analytics, marketing) {
        return {
            necessary:   true,
            preferences: preferences,
            analytics:   analytics,
            marketing:   marketing,
        };
    }

    // B-7: when Reject/Accept-all is pressed inside the preference center, flip the visible
    // category switches to match before posting, so the UI never disagrees with the action.
    function setPrefToggles(value) {
        if (!prefsEl) {
            return;
        }
        qsa('[data-pk-sc-category]', prefsEl).forEach(function (el) {
            el.checked = value;
        });
    }

    function acceptAll(fromPrefs) {
        if (fromPrefs) {
            setPrefToggles(true);
        }
        window.pkConsent.update(buildGrants(true, true, true), 'accept_all');
    }

    function rejectAll(fromPrefs) {
        if (fromPrefs) {
            setPrefToggles(false);
        }
        window.pkConsent.update(buildGrants(false, false, false), 'reject_all');
    }

    function savePrefs() {
        const checks = qsa('[data-pk-sc-category]', prefsEl);
        const grants = { necessary: true, preferences: false, analytics: false, marketing: false };
        checks.forEach(function (el) {
            const cat = el.getAttribute('data-pk-sc-category');
            if (cat && Object.prototype.hasOwnProperty.call(grants, cat)) {
                grants[cat] = el.checked;
            }
        });
        window.pkConsent.update(grants, 'custom');
    }

    function saveOptout() {
        const checks = qsa('[data-pk-sc-category]', bannerEl);
        const grants = { necessary: true, preferences: true, analytics: false, marketing: false };
        checks.forEach(function (el) {
            const cat = el.getAttribute('data-pk-sc-category');
            if (cat && Object.prototype.hasOwnProperty.call(grants, cat)) {
                grants[cat] = el.checked;
            }
        });
        window.pkConsent.update(grants, 'custom');
    }

    function doNotSell() {
        window.pkConsent.update(buildGrants(true, false, false), 'do_not_sell');
    }

    function handleContinue() {
        // Opt-out model: continuing preserves current default-on grants and records
        // the cookie so the banner doesn't re-show on the next page load.
        const grants = {
            necessary:   true,
            preferences: Boolean(cfg.granted.preferences),
            analytics:   Boolean(cfg.granted.analytics),
            marketing:   Boolean(cfg.granted.marketing),
        };
        window.pkConsent.update(grants, 'custom');
    }

    // ============================================================
    // Reopen
    // ============================================================
    function showReopen() {
        const btns = allByAttr('reopen-btn').concat(allByAttr('reopen-float'));
        btns.forEach(function (btn) {
            btn.removeAttribute('hidden');
        });
    }

    // ============================================================
    // Expand / collapse cookie table
    // ============================================================
    function toggleExpand(btn) {
        const slug     = btn.getAttribute('data-pk-sc-for');
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        const table    = qs('#pk-sc-cookies-' + slug);
        const labelEl  = btn.querySelector('[data-pk-sc-expand-label]');
        const catData  = cfg.categories && cfg.categories[slug];
        const count    = catData && catData.cookies ? catData.cookies.length : 0;

        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        if (table) {
            if (expanded) {
                table.setAttribute('hidden', '');
            } else {
                table.removeAttribute('hidden');
            }
        }
        if (labelEl) {
            const verb = expanded ? 'Show' : 'Hide';
            labelEl.textContent = verb + ' ' + count + ' cookie' + (count !== 1 ? 's' : '');
        }
        const svg = btn.querySelector('svg');
        if (svg) {
            svg.style.transform = expanded ? '' : 'rotate(180deg)';
        }
    }

    // ============================================================
    // Wire button clicks via data-pk-sc delegation
    // ============================================================
    function onClick(evt) {
        const btn = evt.target.closest('[data-pk-sc]');
        if (!btn) {
            return;
        }
        const action = btn.getAttribute('data-pk-sc');
        const fromPrefs = prefsEl != null && prefsEl.contains(btn);

        if (action === 'accept-all')  { acceptAll(fromPrefs); return; }
        if (action === 'reject-all')  { rejectAll(fromPrefs); return; }
        if (action === 'open-prefs')  { hideBanner(); openPrefs(); return; }
        if (action === 'save-prefs')  { savePrefs(); closePrefs(); return; }
        // Pointer dismissal for the prefs modal (matches the Escape path): the X button,
        // or a click on the dimmed overlay itself (not a child). Closing here makes no
        // consent change — it just cancels, same as Escape.
        if (action === 'close-prefs') { closePrefs(); return; }
        if (action === 'prefs-overlay' && btn === evt.target) { closePrefs(); return; }
        if (action === 'save-optout') { saveOptout(); return; }
        if (action === 'dnsas')       { doNotSell(); return; }
        if (action === 'continue')    { handleContinue(); return; }
        if (action === 'expand')      { toggleExpand(btn); return; }
        if (action === 'reopen-btn' || action === 'reopen-float') {
            openPrefs();
        }
    }

    // ============================================================
    // Hide on external consent event (e.g. successful POST response)
    // ============================================================
    function onConsentEvent() {
        hideBanner();
        closePrefs();
        showReopen();
    }

    // ============================================================
    // Render gate — JS decides whether to reveal the always-hidden markup
    // ============================================================
    function needsConsent() {
        // cfg.hasConsent is server-computed (valid, non-stale cookie present).
        // The consent cookie is HttpOnly, so JS cannot read document.cookie to
        // detect a prior choice — we MUST rely on this server-emitted flag, or the
        // banner re-shows on every page load even after the visitor has consented.
        return !cfg.hasConsent;
    }

    function init() {
        bannerEl = byAttr('banner');
        prefsEl  = byAttr('prefs');

        if (needsConsent()) {
            showBanner();
        } else {
            showReopen();
        }

        document.addEventListener('click', onClick);
        document.addEventListener('pk-sc-consent', onConsentEvent);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
