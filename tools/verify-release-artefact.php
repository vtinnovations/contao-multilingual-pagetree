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
 * Verifies a built release artefact instead of the readable source tree.
 *
 * It answers five questions:
 *
 *  1. does the artefact still contain everything the framework resolves by name?
 *  2. did the private symbols and the fixed material actually get transformed?
 *  3. is any development-only or maintenance-only material shipped by mistake?
 *  4. does the artefact still carry usable verification material?
 *  5. does the manifest describe exactly the files that are there?
 *
 * Usage: php tools/verify-release-artefact.php <artefact-directory>
 */

$artefact = rtrim((string) ($argv[1] ?? ''), '/');
$failures = [];

/** PHP source with every comment removed, so checks look at code only. */
function strippedCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

if ('' === $artefact || !is_dir($artefact)) {
    fwrite(STDERR, "Usage: php tools/verify-release-artefact.php <artefact-directory>\n");

    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($artefact, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $files[] = substr($file->getPathname(), strlen($artefact) + 1);
    }
}

sort($files);

// 1. Framework contracts.
foreach ([
    'src/VtinnovationsContaoMultilingualPagetreeBundle.php',
    'src/ContaoManager/Plugin.php',
    'src/DependencyInjection/VtinnovationsContaoMultilingualPagetreeExtension.php',
    'src/Resources/config/services.yaml',
    'src/Resources/config/routes.yaml',
    'src/Controller/ChannelUpdateController.php',
    'composer.json',
] as $required) {
    if (!in_array($required, $files, true)) {
        $failures[] = 'Missing from the artefact: '.$required;
    }
}

$routes = (string) @file_get_contents($artefact.'/src/Resources/config/routes.yaml');

if (!str_contains($routes, '/rest/api/v1/contao-multilingual-pagetree-license-updater')) {
    $failures[] = 'The public update path is missing from the artefact.';
}

foreach (glob($artefact.'/contao/dca/*.php') ?: [] as $dca) {
    foreach (preg_split('/\R/', (string) file_get_contents($dca)) ?: [] as $line) {
        if (1 === preg_match("/\\['(Vtinnovations\\\\[^']+)',\\s*'/", $line, $match)
            && !is_file($artefact.'/src/'.str_replace('\\', '/', substr($match[1], strlen('Vtinnovations\\ContaoMultilingualPagetree\\'))).'.php')
        ) {
            $failures[] = 'A DCA callback class no longer exists in the artefact: '.$match[1];
        }
    }
}

// 2. Hardening actually happened.
$hardened = 0;

foreach ($files as $file) {
    if (!str_starts_with($file, 'src/') || !str_ends_with($file, '.php')) {
        continue;
    }

    $contents = (string) file_get_contents($artefact.'/'.$file);

    // What must not survive is an executable destination literal. Every shipped
    // file still carries its copyright banner, and that banner names the vendor
    // website - which is documentation, not something the runtime can call. So
    // the check runs over code with comments removed; a comment cannot redirect
    // traffic, and the files that actually talk to the channel have their
    // comments stripped by the build in any case.
    if (str_contains(strippedCode($contents), 'https://www.v-t.one')) {
        $failures[] = 'A readable fixed destination literal survived in '.$file;
    }

    if (1 === preg_match('~(^|/)(Licensing|License|Licence|Protection|AntiTamper|DRM|VtOne)(/|$)~', $file)) {
        $failures[] = 'The artefact contains an obvious subsystem directory: '.$file;
    }

    if (1 === preg_match('/^\s*(final\s+)?class\s+(C[0-9a-f]{10,})/m', $contents)) {
        ++$hardened;
    }
}

if (0 === $hardened) {
    $failures[] = 'No private symbol was transformed; the artefact is not hardened.';
}

// 3. Nothing development-only or maintenance-only is shipped.
foreach ($files as $file) {
    if (1 === preg_match('~^(tests|tools|docs|\.github)/~', $file)) {
        $failures[] = 'Development material is shipped: '.$file;
    }

    if (1 === preg_match('~symbol-map~', $file)) {
        $failures[] = 'The private symbol map must never be shipped: '.$file;
    }

    if (str_ends_with($file, 'phpunit.xml.dist')) {
        $failures[] = 'Test configuration is shipped: '.$file;
    }
}

// 4. The artefact can actually verify something.
//
// This is the check that stands between a release and an installation that
// fails closed on every signature because its ring is empty or was damaged by
// the transformation. The fragments are reassembled exactly as the runtime does
// and compared against the fingerprint recorded beside them.
$ringKeys = 0;

foreach ($files as $file) {
    if (!str_starts_with($file, 'src/') || !str_ends_with($file, '.php')) {
        continue;
    }

    $contents = (string) file_get_contents($artefact.'/'.$file);

    if (!preg_match_all("/'parts' => \[([^\]]*)\].*?'fingerprint' => '([0-9a-f]{64})'/s", $contents, $entries, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($entries as $entry) {
        $assembled = implode('', array_map(
            static fn (string $part): string => trim($part, " '\"\n\r\t"),
            array_filter(explode(',', $entry[1]), static fn (string $part): bool => '' !== trim($part)),
        ));

        $raw = base64_decode($assembled, true);

        if (false === $raw || 32 !== strlen($raw) || !hash_equals($entry[2], hash('sha256', $raw))) {
            $failures[] = 'The artefact ships verification material that does not reassemble to its recorded fingerprint: '.$file;

            continue;
        }

        ++$ringKeys;
    }
}

if (0 === $ringKeys) {
    $failures[] = 'The artefact ships no usable verification material, so it could never accept a real response.';
}

// 5. The manifest matches the artefact exactly.
$manifestFile = $artefact.'/release-manifest.sha256';

if (!is_file($manifestFile)) {
    $failures[] = 'The release manifest is missing.';
} else {
    $listed = [];

    foreach (preg_split('/\R/', (string) file_get_contents($manifestFile)) ?: [] as $line) {
        if ('' === trim($line)) {
            continue;
        }

        [$digest, $relative] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, '');
        $listed[] = $relative;
        $path = $artefact.'/'.$relative;

        if (!is_file($path)) {
            $failures[] = 'The manifest lists a missing file: '.$relative;

            continue;
        }

        if (!hash_equals($digest, (string) hash_file('sha256', $path))) {
            $failures[] = 'The manifest digest does not match: '.$relative;
        }
    }

    foreach (array_diff($files, $listed, ['release-manifest.sha256']) as $unlisted) {
        $failures[] = 'A file is not covered by the manifest: '.$unlisted;
    }
}

if ([] !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($failures)).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Release artefact verified: %d files, %d transformed symbols, %d usable verification key(s).\n",
    count($files),
    $hardened,
    $ringKeys,
));
