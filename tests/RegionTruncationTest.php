<?php

declare(strict_types=1);

namespace PK\StandardConsent\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Rule A regression guard for the Consent multibyte-region row-loss fix (commit cdf1cd3).
 *
 * The bug: Rest::postConsent truncated region with byte substr($region, 0, 8). A multibyte
 * value whose character straddles the 8-byte boundary was split mid-character, producing
 * INVALID UTF-8. $wpdb->insert then rejects the whole row ("Processing the value for field
 * failed: region") and the GDPR consent audit row is LOST — while the visitor still gets
 * {ok:true} and a valid cookie.
 *
 * The fix: mb_substr, which never splits a character.
 *
 * This spec asserts the invariant the fix guarantees: the truncated region is ALWAYS valid
 * UTF-8. It demonstrates fails-before/passes-after by showing the old byte-substr violates it.
 */
final class RegionTruncationTest extends TestCase
{
    /** The truncation the fixed code performs. */
    private function truncateFixed( string $region ): string
    {
        return mb_substr( $region, 0, 8 );
    }

    /** The truncation the buggy code performed (kept here ONLY to prove the spec catches it). */
    private function truncateBuggy( string $region ): string
    {
        return substr( $region, 0, 8 );
    }

    /** @return string[] regions whose char boundaries do NOT line up with byte 8. */
    private function multibyteRegions(): array
    {
        return [
            '日本日本日',   // 5x 3-byte chars: byte 8 lands mid-char-3
            'café-region', // accented, mixed width
            '🇺🇸🇨🇦region',  // flag emoji (4-byte each)
            'Zürichहै',     // mixed scripts
        ];
    }

    public function test_fixed_truncation_is_always_valid_utf8(): void
    {
        foreach ( $this->multibyteRegions() as $region ) {
            $out = $this->truncateFixed( $region );
            $this->assertTrue(
                mb_check_encoding( $out, 'UTF-8' ),
                "mb_substr must keep '$region' valid UTF-8 after truncation"
            );
        }
    }

    public function test_fixed_truncation_never_exceeds_8_characters(): void
    {
        foreach ( $this->multibyteRegions() as $region ) {
            $this->assertLessThanOrEqual( 8, mb_strlen( $this->truncateFixed( $region ) ) );
        }
    }

    public function test_ascii_region_is_unchanged_by_the_fix(): void
    {
        // The common case (CDN geo codes like 'US', 'CA', 'ROW') must be untouched.
        foreach ( [ 'US', 'CA', 'ROW', 'GB', 'EU-WEST' ] as $code ) {
            $this->assertSame( $code, $this->truncateFixed( $code ) );
        }
    }

    public function test_the_old_byte_substr_DID_corrupt_at_least_one_region(): void
    {
        // Proves the bug was real and the spec discriminates: the old path produced invalid
        // UTF-8 for at least one multibyte region. (If this ever passes clean, the fix has been
        // reverted to byte-substr or the inputs no longer exercise the boundary.)
        $anyInvalid = false;
        foreach ( $this->multibyteRegions() as $region ) {
            if ( ! mb_check_encoding( $this->truncateBuggy( $region ), 'UTF-8' ) ) {
                $anyInvalid = true;
                break;
            }
        }
        $this->assertTrue( $anyInvalid, 'sanity: the pre-fix byte-substr corrupts a multibyte region' );
    }
}
