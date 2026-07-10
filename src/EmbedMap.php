<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps third-party iframe embed URL signatures to the consent category that
 * gates them. Mirrors CookieMap's provider-signature structure; TagRewriter's
 * iframe pass matches an iframe's src against these signatures the same way
 * its script pass matches CookieMap.
 */
final class EmbedMap
{
    /**
     * @var list<array{provider:string,category:string,signatures:list<string>}>
     */
    private const MAP = [
        [
            'provider'   => 'youtube',
            'category'   => 'marketing',
            'signatures' => [
                'youtube.com/embed',
            ],
            // NB: youtube-nocookie.com is YouTube's own no-tracking-cookie embed domain and
            // must NEVER match here — gating it as marketing would be factually wrong.
        ],
        [
            'provider'   => 'vimeo',
            'category'   => 'marketing',
            'signatures' => [
                'player.vimeo.com',
            ],
        ],
        [
            'provider'   => 'google_maps',
            'category'   => 'preferences',
            'signatures' => [
                'google.com/maps/embed',
            ],
        ],
        [
            'provider'   => 'spotify',
            'category'   => 'marketing',
            'signatures' => [
                'open.spotify.com/embed',
            ],
        ],
        [
            'provider'   => 'soundcloud',
            'category'   => 'marketing',
            'signatures' => [
                'w.soundcloud.com/player',
            ],
        ],
        [
            'provider'   => 'facebook',
            'category'   => 'marketing',
            'signatures' => [
                'facebook.com/plugins',
            ],
        ],
        [
            'provider'   => 'twitter_x',
            'category'   => 'marketing',
            'signatures' => [
                'platform.twitter.com',
                'platform.x.com',
            ],
        ],
    ];

    /**
     * Returns the full provider map, with a filter seam for extensibility.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function providers(): array
    {
        return apply_filters( 'pk_sc_embed_map', self::MAP ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook name is prefixed pk_sc_
    }
}
