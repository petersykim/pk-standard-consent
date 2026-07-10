<?php
/**
 * Rule A guard for C6 (scan-config UI -> scanner crawls all configured URLs).
 * Run via wp eval-file. Exits nonzero on fail. Snapshots/restores the consent option.
 * (No declare(strict_types) — eval-file uses eval().)
 *
 * Proves the scanner fetches EVERY URL in scan_urls (not just the home page): hooks
 * pre_http_request to record + short-circuit each request, seeds three URLs, runs the
 * scanner, and asserts all three were requested. A signature cookie is injected into the
 * mocked response of the THIRD (inner) URL to prove inner-page cookies are detected.
 */

use PK\StandardConsent\Scanner;

$prev = get_option( 'pk_standard_consent', [] );
$cfg  = is_array( $prev ) ? $prev : [];

$home = home_url( '/' );
$urls = [ $home, $home . 'page-two/', $home . 'checkout/' ];
$cfg['scan_urls']      = implode( "\n", $urls );
$cfg['scan_sslverify'] = false;
update_option( 'pk_standard_consent', $cfg );

$requested = [];

// Mock every outbound request: record the URL, and for the inner /checkout/ URL return a body
// carrying a GA4 signature so the scanner records a signature cookie from a non-home page.
add_filter(
    'pre_http_request',
    function ( $pre, $args, $url ) use ( &$requested ) {
        $requested[] = $url;
        $body = str_contains( $url, 'checkout' ) ? '<script src="https://www.googletagmanager.com/gtag/js?id=G-X"></script>' : '';
        return [
            'headers'  => [],
            'body'     => $body,
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
        ];
    },
    10,
    3
);

$rows = ( new Scanner() )->run();

$ok = [];
foreach ( $urls as $u ) {
    $ok[ 'requested_' . $u ] = in_array( $u, $requested, true );
}
// At least one analytics cookie was discovered from the inner page's signature.
$ok['inner_signature_detected'] = (bool) array_filter(
    $rows,
    static fn( $r ) => 'analytics' === ( $r['category'] ?? '' )
);

update_option( 'pk_standard_consent', $prev );

if ( ! in_array( false, $ok, true ) ) {
    echo "PASS: scanner crawled all 3 configured URLs and detected an inner-page provider signature\n";
    exit( 0 );
}
fwrite( STDERR, 'FAIL: ' . json_encode( $ok ) . "\n" );
exit( 1 );
