<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

/**
 * Builds a hardened release artefact from the readable development source.
 *
 * What it does, in order:
 *
 *  1. copies the runtime files (src, contao, public, metadata) into a build
 *     directory - development-only material is never copied;
 *  2. renames the private symbols and namespace segments of the registration
 *     path, consistently across PHP sources, service definitions and routes,
 *     while leaving every framework contract untouched: the bundle class, the
 *     Contao Manager plugin, the DI extension, DCA callback classes, models,
 *     migrations, the public route path and the console command names;
 *  3. transforms the fixed destination and the pinned material into per-build
 *     fragments, so the artefact contains no single readable literal for them;
 *  4. strips comments from the registration path only;
 *  5. writes a SHA-256 manifest of the artefact and, outside the artefact, the
 *     private symbol map needed to support the build later;
 *  6. verifies the result: PSR-4 correspondence, `php -l` on every file and the
 *     absence of every old symbol.
 *
 * Nothing here evaluates data, downloads anything or writes to the source tree.
 *
 * Usage:
 *   php tools/build-release.php --out=build/release [--seed=<hex>] [--keep-comments]
 */

$root = dirname(__DIR__);
$options = parseOptions($argv);
$out = rtrim((string) ($options['out'] ?? $root.'/build/release'), '/');
$seed = (string) ($options['seed'] ?? bin2hex(random_bytes(8)));
$keepComments = isset($options['keep-comments']);
$mapFile = dirname($out).'/symbol-map-'.$seed.'.json';

/** Namespace segments of the registration path that may be renamed. */
const RENAMEABLE_NAMESPACES = ['Distribution', 'Packaging', 'Storage', 'Support'];

/**
 * Private classes of the registration path that may be renamed.
 *
 * Everything a framework resolves by name - the bundle, the plugin, the DI
 * extension, models, migrations, DCA callback classes, commands, listeners and
 * the route controller - is deliberately absent from this list.
 */
const RENAMEABLE_CLASSES = [
    'ActivationOutcome', 'ChannelAddress', 'ChannelResponse', 'ChannelTransportException',
    'ChannelTransportInterface', 'ChannelUpdateProcessor', 'ChannelUpdateResult', 'CurlChannelTransport',
    'PackageActivator', 'ProductProfile', 'RegistrationClient', 'RegistrationOutcome', 'UsageSignal',
    'DocumentStatus', 'PackageDocument', 'PackageFormatException', 'PackageReader', 'PackageSeal',
    'SealedPackage', 'ServiceTier',
    'DatabaseRequestLedger', 'FilesystemPackageStore', 'LedgerEntry', 'PackageStoreException',
    'PackageStoreInterface', 'RequestLedgerException', 'RequestLedgerInterface',
    'CanonicalInput', 'DetachedSignature', 'KeyDirectory', 'PinnedMaterial', 'SignatureScheme', 'VerificationKey',
    'CapabilityDecision', 'CapabilityDenial', 'ChannelRequestRejected', 'ChannelRequestVerifier',
    'VerifiedChannelRequest', 'CanonicalHost', 'InstallationIdentity',
];

/** Files copied into the artefact. */
const RUNTIME_PATHS = ['src', 'contao', 'public', 'composer.json', 'README.md', 'README.en.md', 'CHANGELOG.md', 'UPGRADE.md'];

$report = [];

// The pinned material is the trust anchor of the artefact. A build whose ring is
// empty, unusable or no longer reassembles the approved bytes produces an
// installation that can verify nothing and fails closed at the first signature
// check - the exact shape of a "no verification material" incident. So the
// source ring is proven before a single file is copied.
$materialCheck = [];
$materialStatus = 0;
exec(
    escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/tools/check-release-material.php').' 2>&1',
    $materialCheck,
    $materialStatus,
);

if (0 !== $materialStatus) {
    fwrite(STDERR, "Release build refused: the pinned verification material is not release-ready.\n".implode("\n", $materialCheck)."\n");

    exit(1);
}

$report[] = 'Pinned material verified before build: '.trim(implode(' ', $materialCheck));

removeDirectory($out);
mkdir($out, 0755, true);

foreach (RUNTIME_PATHS as $path) {
    if (is_dir($root.'/'.$path)) {
        copyDirectory($root.'/'.$path, $out.'/'.$path);
    } elseif (is_file($root.'/'.$path)) {
        copy($root.'/'.$path, $out.'/'.$path);
    }
}

$files = collectFiles($out);
$map = buildSymbolMap($seed);

applyRenames($out, $files, $map);
$files = collectFiles($out);

transformFixedMaterial($out, $seed);

if (!$keepComments) {
    $report[] = sprintf('Comments stripped from %d registration-path files.', stripComments($out, $map));
}

$manifest = writeManifest($out);
file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
@chmod($mapFile, 0600);

$failures = verify($out, $map);

$report[] = sprintf('Renamed %d namespace segments and %d classes.', count($map['namespaces']), count($map['classes']));
$report[] = sprintf('Manifest covers %d files: %s', count($manifest), $out.'/release-manifest.sha256');
$report[] = 'Private symbol map (never ship this): '.$mapFile;

if ([] !== $failures) {
    fwrite(STDERR, "Release build failed verification:\n".implode("\n", $failures)."\n");

    exit(1);
}

fwrite(STDOUT, implode("\n", $report)."\nRelease artefact built and verified at ".$out."\n");

/**
 * @param list<string> $argv
 *
 * @return array<string, string|bool>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (1 === preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match)) {
            $options[$match[1]] = $match[2] ?? true;
        }
    }

    return $options;
}

/**
 * A deterministic, per-build map of neutral replacement names.
 *
 * @return array{seed: string, namespaces: array<string, string>, classes: array<string, string>}
 */
function buildSymbolMap(string $seed): array
{
    $namespaces = [];
    $classes = [];

    foreach (RENAMEABLE_NAMESPACES as $index => $namespace) {
        $namespaces[$namespace] = 'N'.substr(hash('sha256', $seed.'|ns|'.$namespace), 0, 10 + $index % 3);
    }

    foreach (RENAMEABLE_CLASSES as $index => $class) {
        $classes[$class] = 'C'.substr(hash('sha256', $seed.'|cl|'.$class), 0, 12 + $index % 5);
    }

    return ['seed' => $seed, 'namespaces' => $namespaces, 'classes' => $classes];
}

/**
 * @param list<string>                                                                          $files
 * @param array{seed: string, namespaces: array<string, string>, classes: array<string, string>} $map
 */
function applyRenames(string $out, array $files, array $map): void
{
    foreach ($files as $file) {
        if (!preg_match('/\.(php|yaml|yml)$/', $file)) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        foreach ($map['classes'] as $old => $new) {
            $contents = preg_replace('/\b'.preg_quote($old, '/').'\b/', $new, $contents) ?? $contents;
        }

        foreach ($map['namespaces'] as $old => $new) {
            $contents = str_replace(
                ['ContaoMultilingualPagetree\\'.$old.'\\', 'ContaoMultilingualPagetree\\'.$old.';'],
                ['ContaoMultilingualPagetree\\'.$new.'\\', 'ContaoMultilingualPagetree\\'.$new.';'],
                $contents,
            );
        }

        file_put_contents($file, $contents);
    }

    // PSR-4 requires the path to follow the symbol, so files and directories are
    // moved to match their new names.
    foreach ($map['classes'] as $old => $new) {
        foreach (collectFiles($out) as $file) {
            if (basename($file) === $old.'.php') {
                rename($file, dirname($file).'/'.$new.'.php');
            }
        }
    }

    foreach ($map['namespaces'] as $old => $new) {
        if (is_dir($out.'/src/'.$old)) {
            rename($out.'/src/'.$old, $out.'/src/'.$new);
        }
    }
}

/**
 * Splits the fixed destination and the pinned material into per-build fragments.
 *
 * The result is still plain, readable code - it simply no longer contains one
 * greppable literal. Nothing is executed, decoded from user input or downloaded.
 */
function transformFixedMaterial(string $out, string $seed): void
{
    foreach (collectFiles($out.'/src') as $file) {
        $contents = (string) file_get_contents($file);

        if (str_contains($contents, "private const SCHEME = 'https://';")) {
            $contents = str_replace(
                [
                    "private const SCHEME = 'https://';",
                    "private const HOST_LEFT = 'www.v-t';",
                    "private const HOST_RIGHT = '.one';",
                    "private const VERIFY_PATH = '/api/v1/verify';",
                    "private const SIGNAL_PATH = '/rest/api/v1/log-envoke';",
                ],
                [
                    "private const SCHEME = 'https'.'://';",
                    "private const HOST_LEFT = '".base64_encode('www.v-t')."';",
                    "private const HOST_RIGHT = '".base64_encode('.one')."';",
                    "private const VERIFY_PATH = '".base64_encode('/api/v1/verify')."';",
                    "private const SIGNAL_PATH = '".base64_encode('/rest/api/v1/log-envoke')."';",
                ],
                $contents,
            );

            // The accessors decode the fragments; the values stay constant and
            // are never taken from configuration or a request.
            $contents = str_replace(
                'return self::HOST_LEFT.self::HOST_RIGHT;',
                'return base64_decode(self::HOST_LEFT, true).base64_decode(self::HOST_RIGHT, true);',
                $contents,
            );
            $contents = str_replace(
                'return self::SCHEME.self::host().self::VERIFY_PATH;',
                'return self::SCHEME.self::host().base64_decode(self::VERIFY_PATH, true);',
                $contents,
            );
            $contents = str_replace(
                'return self::SCHEME.self::host().self::SIGNAL_PATH;',
                'return self::SCHEME.self::host().base64_decode(self::SIGNAL_PATH, true);',
                $contents,
            );

            file_put_contents($file, $contents);
        }

        // Pinned public keys are re-split per build, so the same release never
        // ships the same fragment boundaries twice.
        if (preg_match_all("/'parts' => \[([^\]]*)\]/", $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $joined = implode('', array_map(
                    static fn (string $part): string => trim($part, " '\""),
                    array_filter(explode(',', $match[1]), static fn (string $part): bool => '' !== trim($part)),
                ));

                if ('' === $joined) {
                    continue;
                }

                $chunks = str_split($joined, max(3, (int) (strlen($joined) / (3 + hexdec(substr(hash('sha256', $seed.$joined), 0, 1)) % 3))));
                $replacement = "'parts' => ['".implode("', '", $chunks)."']";
                $contents = str_replace($match[0], $replacement, $contents);
            }

            file_put_contents($file, $contents);
        }
    }
}

/**
 * @param array{namespaces: array<string, string>, classes: array<string, string>} $map
 */
function stripComments(string $out, array $map): int
{
    $stripped = 0;
    $directories = array_map(static fn (string $name): string => $out.'/src/'.$name, array_values($map['namespaces']));
    $directories[] = $out.'/src/Security';
    $directories[] = $out.'/src/Helper';
    $directories[] = $out.'/src/Metadata';

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (collectFiles($directory) as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($file));
            $result = '';

            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $result .= is_array($token) ? $token[1] : $token;
            }

            file_put_contents($file, $result);
            ++$stripped;
        }
    }

    return $stripped;
}

/**
 * @return array<string, string>
 */
function writeManifest(string $out): array
{
    $manifest = [];

    foreach (collectFiles($out) as $file) {
        $relative = substr($file, strlen($out) + 1);

        if ('release-manifest.sha256' === $relative) {
            continue;
        }

        $manifest[$relative] = hash_file('sha256', $file);
    }

    ksort($manifest);
    $lines = '';

    foreach ($manifest as $relative => $digest) {
        $lines .= $digest.'  '.$relative."\n";
    }

    file_put_contents($out.'/release-manifest.sha256', $lines);

    return $manifest;
}

/**
 * @param array{namespaces: array<string, string>, classes: array<string, string>} $map
 *
 * @return list<string>
 */
function verify(string $out, array $map): array
{
    $failures = [];

    foreach (collectFiles($out.'/src') as $file) {
        if (!str_ends_with($file, '.php')) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace)
            && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $class)
        ) {
            $expected = $out.'/src/'.str_replace('\\', '/', substr($namespace[1].'\\'.$class[1], strlen('Vtinnovations\\ContaoMultilingualPagetree\\'))).'.php';

            if (realpath($expected) !== realpath($file)) {
                $failures[] = 'PSR-4 mismatch after renaming: '.$file;
            }
        }

        foreach (array_keys($map['classes']) as $old) {
            if (1 === preg_match('/\b'.preg_quote($old, '/').'\b/', $contents)) {
                $failures[] = 'Old symbol survived in '.$file.': '.$old;
            }
        }

        $lint = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $lint, $status);

        if (0 !== $status) {
            $failures[] = 'Syntax error after transformation: '.$file.' - '.implode(' ', $lint);
        }
    }

    foreach (['src/Resources/config/services.yaml', 'src/Resources/config/routes.yaml'] as $configuration) {
        $contents = (string) file_get_contents($out.'/'.$configuration);

        foreach (array_keys($map['classes']) as $old) {
            if (1 === preg_match('/\b'.preg_quote($old, '/').'\b/', $contents)) {
                $failures[] = 'Old symbol survived in '.$configuration.': '.$old;
            }
        }
    }

    // The fragments were re-split for this build. Reassemble them exactly as the
    // runtime does and prove they still produce the approved public key, so a
    // faulty transformation fails the build instead of shipping an artefact that
    // silently trusts nothing.
    $ringEntries = 0;

    foreach (collectFiles($out.'/src') as $file) {
        if (!str_ends_with($file, '.php')) {
            continue;
        }

        $contents = (string) file_get_contents($file);

        if (!preg_match_all("/'parts' => \[([^\]]*)\].*?'fingerprint' => '([0-9a-f]{64})'/s", $contents, $entries, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($entries as $entry) {
            ++$ringEntries;

            $assembled = implode('', array_map(
                static fn (string $part): string => trim($part, " '\"\n\r\t"),
                array_filter(explode(',', $entry[1]), static fn (string $part): bool => '' !== trim($part)),
            ));

            $raw = base64_decode($assembled, true);

            if (false === $raw || 32 !== strlen($raw)) {
                $failures[] = 'A pinned key no longer decodes to usable material after transformation: '.$file;

                continue;
            }

            if (!hash_equals($entry[2], hash('sha256', $raw))) {
                $failures[] = 'A pinned key no longer reassembles to its recorded fingerprint after transformation: '.$file;
            }
        }
    }

    if (0 === $ringEntries) {
        $failures[] = 'The artefact contains no pinned verification material, so it could never verify a real response.';
    }

    $routes = (string) file_get_contents($out.'/src/Resources/config/routes.yaml');

    if (!str_contains($routes, '/rest/api/v1/contao-multilingual-pagetree-license-updater')) {
        $failures[] = 'The public route path must survive the build unchanged.';
    }

    if (!is_file($out.'/src/VtinnovationsContaoMultilingualPagetreeBundle.php')
        || !is_file($out.'/src/ContaoManager/Plugin.php')
    ) {
        $failures[] = 'Framework entry points must survive the build unchanged.';
    }

    return $failures;
}

/**
 * @return list<string>
 */
function collectFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function copyDirectory(string $source, string $target): void
{
    mkdir($target, 0755, true);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $destination = $target.'/'.$iterator->getSubPathname();

        if ($item->isDir()) {
            mkdir($destination, 0755, true);

            continue;
        }

        if ('.DS_Store' === $item->getBasename()) {
            continue;
        }

        copy($item->getPathname(), $destination);
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}
