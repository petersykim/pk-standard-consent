/* global gtag, window, document, fetch, CustomEvent, console */
(function () {
    'use strict';

    const cfg = window.PKStandardConsent;
    if (!cfg) {
        return;
    }

    const SIGNAL_MAP = {
        preferences: ['functionality_storage', 'personalization_storage'],
        analytics: ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization'],
    };

    // Debug logging (B-4): no-op unless the admin flag AND WP_DEBUG are both on (server-gated
    // into cfg.debug). Prefixed so it's greppable in the console.
    function debug() {
        if (!cfg.debug) {
            return;
        }
        const args = Array.prototype.slice.call(arguments);
        args.unshift('[pk-sc]');
        console.log.apply(console, args);
    }

    function isGranted(category) {
        if (category === 'necessary') {
            return true;
        }
        return Boolean(cfg.granted[category]);
    }

    function runInline(b64) {
        // Restore a base64'd inline payload (wp_localize_script data / before / after) as a live
        // script so the external src it configures (e.g. wc_order_attribution.params) finds it.
        if (!b64) {
            return;
        }
        try {
            const s = document.createElement('script');
            s.text = atob(b64);
            document.head.appendChild(s);
        } catch (e) {
            debug('inline restore failed', e);
        }
    }

    // One physical script file can carry TWO placeholders: the handle gate dequeues it, but
    // WP re-prints it as a dependency of another enqueued handle and the tag rewriter inerts
    // that copy under its signature category. Activating both double-executes the script
    // (round-5 F2: sourcebuster fetched and run twice). Dedupe by src path at activation.
    const activatedSrcs = {};

    function srcKey(src) {
        return src ? src.split('#')[0].split('?')[0] : '';
    }

    function alreadyActive(key) {
        if (!key) {
            return false;
        }
        if (activatedSrcs[key]) {
            return true;
        }
        const live = document.querySelectorAll('script[src]');
        for (let i = 0; i < live.length; i++) {
            if (live[i].type !== 'text/plain' && srcKey(live[i].getAttribute('src')) === key) {
                return true;
            }
        }
        return false;
    }

    function unblockScript(el) {
        const src = el.getAttribute('data-pk-sc-src');
        const key = srcKey(src);
        if (src && alreadyActive(key)) {
            debug('skip duplicate placeholder for', key);
            el.parentNode.removeChild(el);
            return;
        }
        if (key) {
            activatedSrcs[key] = true;
        }
        // Localize data + any "before" inline must run BEFORE the external script loads, so the
        // script finds its config (this is the wc-order-attribution "reading 'params'" fix).
        runInline(el.getAttribute('data-pk-sc-data'));
        runInline(el.getAttribute('data-pk-sc-before'));
        const next = document.createElement('script');
        if (src) {
            next.src = src;
        } else if (el.textContent) {
            // Inline tags (TagRewriter keeps the body inside the inert placeholder):
            // re-run the original code by copying it into a live script element.
            next.text = el.textContent;
        }
        if (el.hasAttribute('async')) {
            next.async = true;
        }
        if (el.hasAttribute('defer')) {
            next.defer = true;
        }
        const after = el.getAttribute('data-pk-sc-after');
        if (after) {
            next.addEventListener('load', function () { runInline(after); });
        }
        document.head.appendChild(next);
        el.parentNode.removeChild(el);
    }

    function unblockAll(category) {
        const selector = 'script[type="text/plain"][data-pk-sc-category="' + category + '"]';
        const els = document.querySelectorAll(selector);
        for (let i = 0; i < els.length; i++) {
            unblockScript(els[i]);
        }
    }

    function buildSignals(grants) {
        const signals = { security_storage: 'granted' };
        const categories = Object.keys(SIGNAL_MAP);
        for (let i = 0; i < categories.length; i++) {
            const cat = categories[i];
            const value = grants[cat] ? 'granted' : 'denied';
            const gcmSignals = SIGNAL_MAP[cat];
            for (let j = 0; j < gcmSignals.length; j++) {
                signals[gcmSignals[j]] = value;
            }
        }
        return signals;
    }

    function postConsent(grants, method, onSuccess, onError) {
        const body = JSON.stringify({
            categories: grants,
            region: cfg.region || '',
            version: cfg.version,
            method: method || 'custom',
        });
        fetch(cfg.restUrl + '/consent', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce,
            },
            body: body,
        })
            .then(function (res) {
                if (res.ok) {
                    onSuccess(grants);
                } else {
                    onError();
                }
            })
            .catch(function () {
                onError();
            });
    }

    // D-cookie-autodelete: on withdrawal, proactively delete the already-set cookies for a
    // denied category (sourced from the scan results in cfg.categories). A trailing '_' name
    // is treated as a prefix (e.g. GA4's _ga_<id>) and all matching cookies are cleared.
    function deleteCookie(name) {
        const domain = '; domain=' + location.hostname;
        const base = '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        document.cookie = name + base;
        document.cookie = name + base + domain;
        document.cookie = name + base + '; domain=.' + location.hostname;
    }

    function clearCategoryCookies(category) {
        const cats = cfg.categories || {};
        const data = cats[category];
        if (!data || !data.cookies) {
            return;
        }
        const present = document.cookie.split(';').map(function (c) { return c.split('=')[0].trim(); });
        data.cookies.forEach(function (c) {
            const name = c.name || '';
            if (!name) {
                return;
            }
            if (name.charAt(name.length - 1) === '_') {
                present.forEach(function (p) { if (p.indexOf(name) === 0) { deleteCookie(p); } });
            } else if (present.indexOf(name) !== -1) {
                deleteCookie(name);
            }
        });
    }

    function applyGrants(grants) {
        debug('applyGrants', grants);
        if (cfg.consentModeV2 && typeof gtag === 'function') {
            gtag('consent', 'update', buildSignals(grants));
        }
        const categories = Object.keys(grants);
        for (let i = 0; i < categories.length; i++) {
            if (grants[categories[i]]) {
                debug('unblock category', categories[i]);
                unblockAll(categories[i]);
            } else if (categories[i] !== 'necessary') {
                debug('clear cookies for denied category', categories[i]);
                clearCategoryCookies(categories[i]);
            }
        }
        document.dispatchEvent(new CustomEvent('pk-sc-consent', { detail: { grants: grants } }));
    }

    // GPC (C8): Global Privacy Control is a US opt-out signal (CPRA/Colorado). When the
    // browser sets navigator.globalPrivacyControl AND the visitor has no stored consent yet,
    // auto-apply an opt-out (analytics + marketing off, necessary + preferences kept) and log
    // it via the 'do_not_sell' method. Records the cookie so it fires once, not every load.
    function maybeApplyGpc() {
        if (cfg.hasConsent || navigator.globalPrivacyControl !== true) {
            return false;
        }
        debug('GPC detected — auto-applying opt-out');
        window.pkConsent.update(
            { necessary: true, preferences: true, analytics: false, marketing: false },
            'do_not_sell'
        );
        return true;
    }

    window.pkConsent = {
        update: function (grants, method) {
            postConsent(
                grants,
                method,
                function (g) { applyGrants(g); },
                function () { console.warn('pk-sc: consent POST failed'); }
            );
        },
    };

    window.addEventListener('load', function () {
        debug('gate init', { hasConsent: cfg.hasConsent, region: cfg.region, granted: cfg.granted });
        // GPC takes precedence: if it auto-opts-out, it posts + applies its own grants and
        // dispatches pk-sc-consent (which hides the banner), so skip the default unblock pass.
        if (maybeApplyGpc()) {
            return;
        }
        const categories = Object.keys(cfg.granted);
        for (let i = 0; i < categories.length; i++) {
            if (isGranted(categories[i])) {
                unblockAll(categories[i]);
            }
        }
    });
}());
