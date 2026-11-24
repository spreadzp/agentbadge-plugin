<?php
/**
 * SLICE-262-33 — build release-manifest.json per D-262-Q12:
 * version, protocol, WP/PHP range, changelog, checksum, security
 * contact. Zero dependencies — the same PHP that runs the plugin.
 *
 * Usage: php bin/build-release.php [out-file]
 *   default out: dist/release-manifest.json
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$out  = $argv[1] ?? $root . '/dist/release-manifest.json';

/** Files shipped to users = the checksum + zip scope. Keep in sync
 *  with .distignore (the two lists must never disagree). */
$ship = static function () use ( $root ): array {
	$files = [];
	$dirs  = [ 'includes', 'templates', 'assets', 'contracts' ];
	foreach ( $dirs as $dir ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			if ( $f->isFile() && $f->getFilename() !== 'MANIFEST.md' ) {
				$files[] = $f->getPathname();
			}
		}
	}
	foreach ( [ 'agentbadge.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'SECURITY.md' ] as $f ) {
		if ( is_file( "$root/$f" ) ) {
			$files[] = "$root/$f";
		}
	}
	sort( $files );
	return array_map(
		static fn( string $p ): string => ltrim( substr( $p, strlen( $root ) ), '/' ),
		$files
	);
};

$pluginHeader = file_get_contents( "$root/agentbadge.php" );
$readme       = file_get_contents( "$root/readme.txt" );

$pick = static function ( string $re, string $src, string $name ): string {
	if ( ! preg_match( $re, $src, $m ) ) {
		fwrite( STDERR, "manifest: cannot parse $name\n" );
		exit( 2 );
	}
	return trim( $m[1] );
};

// changelog — the == Changelog == section's latest = X.Y.Z = block
$changelog = [];
if ( preg_match( '/==\s*Changelog\s*==(.+?)(?=\n==\s|\z)/s', $readme, $sec ) ) {
	preg_match_all( '/^=\s*([0-9.]+)\s*=\s*\n((?:\*[^\n]*\n?)+)/m', $sec[1], $mm, PREG_SET_ORDER );
	foreach ( $mm as $entry ) {
		$items                  = array_map(
			static fn( string $l ): string => trim( ltrim( trim( $l ), '*' ) ),
			array_filter( explode( "\n", trim( $entry[2] ) ) )
		);
		$changelog[ $entry[1] ] = $items;
	}
}

$registry = json_decode(
	file_get_contents( "$root/contracts/pull-protocol/capability-registry.v1.json" ),
	true,
	flags: JSON_THROW_ON_ERROR
);

$checksums = [];
foreach ( $ship() as $rel ) {
	$checksums[ $rel ] = hash_file( 'sha256', "$root/$rel" );
}

$security = null;
if ( is_file( "$root/SECURITY.md" ) &&
	preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+|https?:\/\/\S+/', file_get_contents( "$root/SECURITY.md" ), $sm ) ) {
	$security = $sm[0];
}

$manifest = [
	'manifest_version' => '1',
	'plugin'           => 'agentbadge',
	'version'          => $pick( '/Version:\s*(\S+)/', $pluginHeader, 'plugin version' ),
	'protocol'         => [
		'pull'     => $registry['registry_version'] ?? null,
		'registry' => 'contracts/pull-protocol/capability-registry.v1.json',
	],
	'requires'         => [
		'wordpress' => $pick( '/Requires at least:\s*(\S+)/', $readme, 'WP min version' ),
		'php'       => $pick( '/Requires PHP:\s*(\S+)/', $pluginHeader, 'PHP min version' ),
	],
	'changelog'        => $changelog,
	'security_contact' => $security,
	'checksums_sha256' => $checksums,
	'generated_at'     => gmdate( 'c' ),
];

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0755, true );
}
file_put_contents( $out, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo "release manifest → $out ({$manifest['version']}, " . count( $checksums ) . " files)\n";
