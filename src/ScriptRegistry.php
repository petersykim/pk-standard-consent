<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ScriptRegistry
{
    private static ?self $instance = null;

    /**
     * @var array<string, array{category: Category, src: string}>
     */
    private array $registered = [];

    /**
     * @var array<string, array{category: Category, src: string}>
     */
    private array $blocked = [];

    public static function instance(): self
    {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns all registered scripts.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->registered;
    }

    public function register( string $handle, Category $category, string $src ): void
    {
        if ( ! $category->isGateable() ) {
            throw new \InvalidArgumentException( "Category '{$category->value}' is not gateable; necessary scripts are never blocked." );
        }
        $this->registered[ $handle ] = [
            'category' => $category,
            'src'      => $src,
        ];
    }

    public function blockUnconsented(): void
    {
        $payload   = Cookie::read();
        $stale     = null !== $payload && Cookie::isStale( $payload );
        $use_grant = null !== $payload && ! $stale;

        foreach ( $this->registered as $handle => $entry ) {
            if ( ! wp_script_is( $handle, 'enqueued' ) ) {
                continue;
            }

            $grants  = $use_grant && is_array( $payload['c'] ?? null ) ? $payload['c'] : [];
            $granted = ! empty( $grants[ $entry['category']->value ] );

            if ( $granted ) {
                continue;
            }

            global $wp_scripts;
            $src = $entry['src'];
            if ( '' === $src ) {
                $src = isset( $wp_scripts->registered[ $handle ] ) ? (string) $wp_scripts->registered[ $handle ]->src : '';
            }

            // Capture the handle's inline data (wp_localize_script config like
            // wc_order_attribution.params, plus before/after inline scripts) so it can be
            // restored WITH the script on consent. Dequeuing alone drops this inline data; the
            // main script then runs without its config and throws (e.g. "reading 'params'").
            $inline = [ 'data' => '', 'before' => '', 'after' => '' ];
            if ( isset( $wp_scripts->registered[ $handle ] ) ) {
                $obj             = $wp_scripts->registered[ $handle ];
                $inline['data']  = (string) ( $obj->extra['data'] ?? '' );
                $inline['before'] = is_array( $obj->extra['before'] ?? null ) ? implode( "\n", array_filter( (array) $obj->extra['before'] ) ) : '';
                $inline['after']  = is_array( $obj->extra['after'] ?? null ) ? implode( "\n", array_filter( (array) $obj->extra['after'] ) ) : '';
            }

            wp_dequeue_script( $handle );
            // Stop core from printing the inline data/before/after for this dequeued handle —
            // we re-emit it (gated) ourselves in emitBlocked so it rehydrates with the script.
            $wp_scripts->dequeue( $handle );
            $this->blocked[ $handle ] = [
                'category' => $entry['category'],
                'src'      => $src,
                'inline'   => $inline,
            ];
        }
    }

    public function emitBlocked(): void
    {
        foreach ( $this->blocked as $handle => $entry ) {
            $inline = is_array( $entry['inline'] ?? null ) ? $entry['inline'] : [];
            // The inline config (localize data + before/after) is base64'd into a data attribute
            // so consent-gate.js can eval it BEFORE activating the script on consent — restoring
            // e.g. window.wc_order_attribution.params so the script doesn't throw. base64 keeps
            // arbitrary JS out of HTML-attribute parsing.
            $data_attr   = $inline['data'] !== '' ? sprintf( ' data-pk-sc-data="%s"', esc_attr( base64_encode( (string) $inline['data'] ) ) ) : '';
            $before_attr = ! empty( $inline['before'] ) ? sprintf( ' data-pk-sc-before="%s"', esc_attr( base64_encode( (string) $inline['before'] ) ) ) : '';
            $after_attr  = ! empty( $inline['after'] ) ? sprintf( ' data-pk-sc-after="%s"', esc_attr( base64_encode( (string) $inline['after'] ) ) ) : '';
            // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- type="text/plain" elements are inert placeholders re-activated by consent-gate.js; they are not active script tags.
            printf(
                '<script type="text/plain" data-pk-sc-category="%s" data-pk-sc-handle="%s" data-pk-sc-src="%s"%s%s%s></script>' . "\n",
                esc_attr( $entry['category']->value ),
                esc_attr( $handle ),
                esc_url( $entry['src'] ),
                $data_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
                $before_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $after_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            );
            // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        }
    }
}
