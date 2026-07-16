<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Output-buffer rewriter that neutralizes consent-relevant <script> tags REGARDLESS
 * of how they were injected (theme header.php, GTM snippet, an inline third-party
 * tag, a head-injected async tag) — not just WP-enqueued handles.
 *
 * Two classes of tag are caught while consent for the matching category is absent:
 *   (a) <script src="..."> whose src matches a CookieMap provider signature; the
 *       provider's category drives the gate, mirroring the scanner's detection.
 *   (b) inline <script data-pk-sc-category="analytics">...</script> — the documented
 *       authoring convention for hand-added tags (GA4 inline, Pixel init, etc.).
 *
 * Each matched tag is rewritten to type="text/plain" with the data-pk-sc-* hooks the
 * gate JS (consent-gate.js) re-activates on grant. The whole page is buffered from
 * template_redirect so head-injected async tags are covered (D-prior-js-block).
 */
final class TagRewriter
{
    /** @var array<string, bool> category-slug => granted */
    private array $grants = [];

    /** @var list<array{category: string, signatures: list<string>}> */
    private array $provider_signatures = [];

    /** @var list<array{category: string, signatures: list<string>}> */
    private array $embed_signatures = [];

    /** True once this response has had at least one script/iframe made inert (gated). */
    private bool $gated = false;

    /**
     * Opens the page-wide output buffer on template_redirect.
     * No-op for feeds, REST, and admin so only real front-end HTML is rewritten.
     */
    public function start(): void
    {
        if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        $this->grants              = $this->resolveGrants();
        $this->provider_signatures = $this->loadProviderSignatures();
        $this->embed_signatures    = $this->loadEmbedSignatures();
        ob_start( [ $this, 'rewrite' ] );
    }

    /**
     * Buffer callback. Rewrites every <script> tag whose src matches an ungated
     * provider, or that carries a data-pk-sc-category for an ungated category.
     */
    public function rewrite( string $html ): string
    {
        if ( '' === $html ) {
            return $html;
        }

        if ( false !== stripos( $html, '<script' ) ) {
            // NB: the lazy body terminates at the first `</script>`, which is EXACTLY how an HTML
            // parser behaves — a raw `</script>` cannot appear inside an inline script body (it must
            // be escaped `<\/script>`), so the browser ends the script there too. (Round-1 audit F1
            // flagged this as truncation; it is spec-correct HTML parsing, not a defect.)
            //
            // Attrs = a run of (double-quoted | single-quoted | non-`>`) chars, so a literal `>`
            // INSIDE a quoted attribute value (e.g. data-config="a>b") does NOT truncate the attrs
            // capture. The old `[^>]*` stopped at the first `>`, dropping any data-pk-sc-category
            // that appeared after it — so a tagged analytics script sailed through UNGATED (consent
            // bypass, C-005). A theme header.php / pasted GTM snippet echoes such a script via
            // wp_head with no wpautop, so a raw `>` reaches here intact; this is a live bypass.
            $html = (string) preg_replace_callback(
                '#<script\b((?:"[^"]*"|\'[^\']*\'|[^>\'"])*)>(.*?)</script>#is',
                [ $this, 'rewriteTag' ],
                $html
            );
        }

        if ( false !== stripos( $html, '<iframe' ) ) {
            // Match the OPENING iframe tag and optionally consume a body+close if present.
            // The old pattern required `>(.*?)</iframe>`, so a self-closing `<iframe .../>` or an
            // unclosed iframe (both valid, both common from embed copy-paste — Google Maps' own
            // "Embed a map" code is a gated provider here) never matched and passed through LIVE,
            // ungated, from first page load — a consent bypass (S1, 2026-07-15). rewriteIframe only
            // uses the attrs capture, so we no longer require a body/close.
            $html = (string) preg_replace_callback(
                '#<iframe\b([^>]*?)\s*/?>(?:.*?</iframe>)?#is',
                [ $this, 'rewriteIframe' ],
                $html
            );
        }

        // If this page actually had something gated (a script/iframe made inert for THIS visitor's
        // consent state), it must never be served from a cache to a DIFFERENT visitor — their
        // consent state differs, so a cached copy would run (or block) the wrong trackers. A site
        // owner has no control over what cache a client puts in front (CDN, page-cache plugin), so
        // the gate declares the page uncacheable itself. Sent ONLY when something was gated: a page
        // with no trackers, or a fully-consented visitor's page, stays cacheable. This runs inside
        // the ob_start callback, before the buffer flushes, so headers are still open.
        if ( $this->gated && ! headers_sent() ) {
            header( 'Cache-Control: private, no-store, max-age=0' );
            header( 'Vary: Cookie', false );
        }

        return $html;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $m  [full, attrs, body]
     */
    private function rewriteTag( array $m ): string
    {
        $attrs = $m[1];
        $body  = $m[2];

        // Already inert (our own placeholders, or hand-authored text/plain) — leave it.
        if ( false !== stripos( $attrs, 'text/plain' ) ) {
            return $m[0];
        }

        $category = $this->matchCategory( $attrs );
        if ( null === $category || $this->isGranted( $category ) ) {
            return $m[0];
        }

        return $this->renderInert( $attrs, $body, $category );
    }

    /**
     * Resolves a tag's gate category from either its src (provider signature) or an
     * explicit data-pk-sc-category attribute. Returns null when the tag isn't gateable.
     */
    private function matchCategory( string $attrs ): ?string
    {
        $explicit = $this->attrValue( $attrs, 'data-pk-sc-category' );
        if ( '' !== $explicit ) {
            $cat = Category::tryFrom( $explicit );
            return ( null !== $cat && $cat->isGateable() ) ? $cat->value : null;
        }

        $src = $this->attrValue( $attrs, 'src' );
        if ( '' === $src ) {
            return null;
        }
        foreach ( $this->provider_signatures as $provider ) {
            foreach ( $provider['signatures'] as $sig ) {
                if ( str_contains( $src, $sig ) ) {
                    return $provider['category'];
                }
            }
        }
        return null;
    }

    /**
     * Builds the inert placeholder. A src tag carries data-pk-sc-src (re-created live
     * by the gate); an inline tag keeps its body inside the text/plain element, which
     * the gate re-executes by cloning into an active <script>.
     */
    private function renderInert( string $attrs, string $body, string $category ): string
    {
        $this->gated = true; // this response carries per-visitor gated content — mark it uncacheable.
        $src = $this->attrValue( $attrs, 'src' );

        $out = '<script type="text/plain" data-pk-sc-category="' . esc_attr( $category ) . '"';
        if ( '' !== $src ) {
            $out .= ' data-pk-sc-src="' . esc_url( $src ) . '"';
        }
        if ( $this->hasBareAttr( $attrs, 'async' ) ) {
            $out .= ' async';
        }
        if ( $this->hasBareAttr( $attrs, 'defer' ) ) {
            $out .= ' defer';
        }
        $out .= '>';
        // Inline body is preserved verbatim inside the inert tag; the browser does not
        // execute type="text/plain" content, so this is safe until the gate clones it.
        $out .= '' === $src ? $body : '';
        $out .= '</script>';

        return $out;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $m  [full, attrs, body]
     */
    private function rewriteIframe( array $m ): string
    {
        $attrs = $m[1];

        $category = $this->matchEmbedCategory( $attrs );
        if ( null === $category || $this->isGranted( $category ) ) {
            return $m[0];
        }

        return $this->renderEmbedPlaceholder( $attrs, $category );
    }

    /**
     * Resolves an iframe's gate category from either its src (EmbedMap signature) or an
     * explicit data-pk-sc-category attribute. Returns null when the iframe isn't gateable.
     * Precedence matches matchCategory() for scripts: explicit attribute first.
     */
    private function matchEmbedCategory( string $attrs ): ?string
    {
        $explicit = $this->attrValue( $attrs, 'data-pk-sc-category' );
        if ( '' !== $explicit ) {
            $cat = Category::tryFrom( $explicit );
            return ( null !== $cat && $cat->isGateable() ) ? $cat->value : null;
        }

        $src = $this->attrValue( $attrs, 'src' );
        if ( '' === $src ) {
            return null;
        }
        foreach ( $this->embed_signatures as $provider ) {
            foreach ( $provider['signatures'] as $sig ) {
                if ( str_contains( $src, $sig ) ) {
                    return $provider['category'];
                }
            }
        }
        return null;
    }

    /**
     * Builds the placeholder markup that replaces a gated iframe. The original attribute
     * string is preserved (base64) so the gate JS can rebuild the real iframe from an
     * attribute allowlist on grant, without ever re-parsing untrusted HTML.
     */
    private function renderEmbedPlaceholder( string $attrs, string $category ): string
    {
        $this->gated = true; // this response carries per-visitor gated content — mark it uncacheable.
        $src   = $this->attrValue( $attrs, 'src' );
        $ratio = $this->resolveRatio( $attrs );

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries opaque markup, not obfuscating anything
        $attrs_b64 = base64_encode( $attrs );

        $label   = Category::from( $category )->value;
        $message = sprintf(
            /* translators: %s: consent category label (e.g. "marketing") */
            __( 'This embedded content is blocked until you allow %s cookies.', 'pk-standard-consent' ),
            $label
        );
        $allow_label = sprintf(
            /* translators: %s: consent category label (e.g. "marketing") */
            __( 'Allow %s cookies & load', 'pk-standard-consent' ),
            $label
        );

        // The --pk-sc-ratio custom property must be inline: each embed instance can carry a
        // different aspect ratio (from its own width/height attrs), which a static stylesheet
        // cannot express per-element.
        $out  = '<div class="pk-sc-embed" data-pk-sc-category="' . esc_attr( $category ) . '"';
        $out .= ' data-pk-sc-src="' . esc_attr( $src ) . '"';
        $out .= ' data-pk-sc-attrs="' . esc_attr( $attrs_b64 ) . '"';
        $out .= ' style="--pk-sc-ratio: ' . esc_attr( $ratio ) . '">';
        $out .= '<div class="pk-sc-embed__body">';
        $out .= '<svg class="pk-sc-embed__icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
        $out .= '<p class="pk-sc-embed__message">' . esc_html( $message ) . '</p>';
        $out .= '<div class="pk-sc-embed__actions">';
        $out .= '<button class="pk-sc-btn pk-sc-btn--primary" type="button" data-pk-sc="embed-allow" data-pk-sc-category="' . esc_attr( $category ) . '">' . esc_html( $allow_label ) . '</button>';
        // Reuses the SAME data-pk-sc="open-prefs" hook the banner's "Manage preferences" button
        // uses, so consent-banner.js's existing global click delegator opens the real modal —
        // no separate reimplementation needed.
        $out .= '<button class="pk-sc-btn pk-sc-btn--ghost" type="button" data-pk-sc="open-prefs">' . esc_html__( 'Manage preferences', 'pk-standard-consent' ) . '</button>';
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</div>';

        return $out;
    }

    /**
     * Reads width/height attrs; both must be numeric to use them, else default to 16/9.
     */
    private function resolveRatio( string $attrs ): string
    {
        $width  = $this->attrValue( $attrs, 'width' );
        $height = $this->attrValue( $attrs, 'height' );
        if ( is_numeric( $width ) && is_numeric( $height ) && (float) $height > 0.0 ) {
            return $width . '/' . $height;
        }
        return '16/9';
    }

    private function attrValue( string $attrs, string $name ): string
    {
        if ( ! preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $attrs, $mm ) ) {
            return '';
        }
        return '' !== ( $mm[2] ?? '' ) ? $mm[2] : ( $mm[3] ?? '' );
    }

    private function hasBareAttr( string $attrs, string $name ): bool
    {
        return 1 === preg_match( '#\b' . preg_quote( $name, '#' ) . '\b#i', $attrs );
    }

    private function isGranted( string $category ): bool
    {
        return ! empty( $this->grants[ $category ] );
    }

    /** @return array<string, bool> */
    private function resolveGrants(): array
    {
        $payload   = Cookie::read();
        $stale     = null !== $payload && Cookie::isStale( $payload );
        $use_grant = null !== $payload && ! $stale;
        $c         = $use_grant && is_array( $payload['c'] ?? null ) ? $payload['c'] : [];

        return [
            'preferences' => ! empty( $c['preferences'] ),
            'analytics'   => ! empty( $c['analytics'] ),
            'marketing'   => ! empty( $c['marketing'] ),
        ];
    }

    /** @return list<array{category: string, signatures: list<string>}> */
    private function loadProviderSignatures(): array
    {
        $out = [];
        foreach ( CookieMap::providers() as $provider ) {
            $category = is_string( $provider['category'] ?? null ) ? $provider['category'] : '';
            $cat      = Category::tryFrom( $category );
            if ( null === $cat || ! $cat->isGateable() ) {
                continue;
            }
            $signatures = [];
            foreach ( (array) ( $provider['signatures'] ?? [] ) as $sig ) {
                if ( is_string( $sig ) && '' !== $sig ) {
                    $signatures[] = $sig;
                }
            }
            $out[] = [
                'category'   => $cat->value,
                'signatures' => $signatures,
            ];
        }
        return $out;
    }

    /** @return list<array{category: string, signatures: list<string>}> */
    private function loadEmbedSignatures(): array
    {
        $out = [];
        foreach ( EmbedMap::providers() as $provider ) {
            $category = is_string( $provider['category'] ?? null ) ? $provider['category'] : '';
            $cat      = Category::tryFrom( $category );
            if ( null === $cat || ! $cat->isGateable() ) {
                continue;
            }
            $signatures = [];
            foreach ( (array) ( $provider['signatures'] ?? [] ) as $sig ) {
                if ( is_string( $sig ) && '' !== $sig ) {
                    $signatures[] = $sig;
                }
            }
            $out[] = [
                'category'   => $cat->value,
                'signatures' => $signatures,
            ];
        }
        return $out;
    }
}
