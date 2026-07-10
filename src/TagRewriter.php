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
    private array $providerSignatures = [];

    /**
     * Opens the page-wide output buffer on template_redirect.
     * No-op for feeds, REST, and admin so only real front-end HTML is rewritten.
     */
    public function start(): void
    {
        if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        $this->grants             = $this->resolveGrants();
        $this->providerSignatures = $this->loadProviderSignatures();
        ob_start( [ $this, 'rewrite' ] );
    }

    /**
     * Buffer callback. Rewrites every <script> tag whose src matches an ungated
     * provider, or that carries a data-pk-sc-category for an ungated category.
     */
    public function rewrite( string $html ): string
    {
        if ( '' === $html || false === stripos( $html, '<script' ) ) {
            return $html;
        }

        // NB: the lazy body terminates at the first `</script>`, which is EXACTLY how an HTML
        // parser behaves — a raw `</script>` cannot appear inside an inline script body (it must
        // be escaped `<\/script>`), so the browser ends the script there too. (Round-1 audit F1
        // flagged this as truncation; it is spec-correct HTML parsing, not a defect.)
        return (string) preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#is',
            [ $this, 'rewriteTag' ],
            $html
        );
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
        foreach ( $this->providerSignatures as $provider ) {
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
        $src = $this->attrValue( $attrs, 'src' );

        $out  = '<script type="text/plain" data-pk-sc-category="' . esc_attr( $category ) . '"';
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
}
