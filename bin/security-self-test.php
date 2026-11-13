<?php
/**
 * WP-CLI / eval-file entry for Security Self_Test (S1+S2).
 *
 * Usage (from WordPress root):
 *   wp eval-file wp-content/plugins/prime-fleet/bin/security-self-test.php
 *
 * @package Prime_Fleet
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via WP-CLI eval-file from a WordPress install.\n" );
	exit( 1 );
}

if ( ! class_exists( '\Prime_Fleet\Security\Self_Test' ) ) {
	fwrite( STDERR, "Prime Fleet Security\\Self_Test not found — is the plugin active?\n" );
	exit( 1 );
}

$report = \Prime_Fleet\Security\Self_Test::run();

$line = sprintf(
	"[prime-fleet security self-test] ok=%s passed=%d failed=%d skipped=%d\n",
	$report['ok'] ? 'yes' : 'NO',
	(int) $report['passed'],
	(int) $report['failed'],
	(int) $report['skipped']
);
fwrite( STDOUT, $line );

foreach ( $report['modules'] as $key => $mod ) {
	fwrite( STDOUT, "\n## {$mod['title']} ({$key})\n" );
	foreach ( $mod['cases'] as $case ) {
		$mark = 'pass' === $case['status'] ? 'PASS' : ( 'fail' === $case['status'] ? 'FAIL' : 'SKIP' );
		fwrite( STDOUT, sprintf( "  [%s] %s", $mark, $case['label'] ) );
		if ( 'fail' === $case['status'] && $case['detail'] ) {
			$detail = preg_replace( '/\s+/', ' ', (string) $case['detail'] );
			fwrite( STDOUT, ' — ' . substr( $detail, 0, 160 ) );
		}
		fwrite( STDOUT, "\n" );
	}
}

$out_dir = WP_CONTENT_DIR . '/uploads/prime-fleet-qa';
if ( ! is_dir( $out_dir ) ) {
	wp_mkdir_p( $out_dir );
}
$file = $out_dir . '/security-s1-s2-' . gmdate( 'Ymd-His' ) . '.json';
file_put_contents( $file, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
fwrite( STDOUT, "\nReport written: {$file}\n" );

exit( $report['ok'] ? 0 : 2 );
