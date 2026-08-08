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
 * Case-sensitive, dependency-free identity and PSR-4 validation.
 *
 * Legacy database identifiers are deliberately not part of this check; their
 * compatibility policy is documented in the README and asserted by tests.
 */

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$errors = [];

$expected = [
    'name' => 'vtinnovations/contao-multilingual-pagetree',
    'namespace' => 'Vtinnovations\\ContaoMultilingualPagetree\\',
    'bundle' => 'Vtinnovations\\ContaoMultilingualPagetree\\VtinnovationsContaoMultilingualPagetreeBundle',
    'plugin' => 'Vtinnovations\\ContaoMultilingualPagetree\\ContaoManager\\Plugin',
    'identifier' => 'contao_multilingual_pagetree',
];

if (($composer['name'] ?? null) !== $expected['name']) {
    $errors[] = 'Unexpected Composer package identity.';
}
if (($composer['autoload']['psr-4'][$expected['namespace']] ?? null) !== 'src/') {
    $errors[] = 'The production PSR-4 namespace is missing or incorrectly cased.';
}
if (($composer['extra']['contao-manager-plugin'] ?? null) !== $expected['plugin']) {
    $errors[] = 'The Contao Manager plugin identity is incorrect.';
}

$bundleFile = $root.'/src/VtinnovationsContaoMultilingualPagetreeBundle.php';
if (!is_file($bundleFile) || !str_contains((string) file_get_contents($bundleFile), 'class VtinnovationsContaoMultilingualPagetreeBundle')) {
    $errors[] = 'The correctly cased main bundle class/file is missing.';
}

$roots = ['src' => $expected['namespace'], 'tests' => $expected['namespace'].'Tests\\'];
foreach ($roots as $directory => $prefix) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $match)) {
            continue;
        }
        $relativeDirectory = dirname(substr($file->getPathname(), strlen($root.'/'.$directory) + 1));
        $expectedNamespace = rtrim($prefix.str_replace(DIRECTORY_SEPARATOR, '\\', '.' === $relativeDirectory ? '' : $relativeDirectory), '\\');
        if ($match[1] !== $expectedNamespace) {
            $errors[] = sprintf('Namespace/path mismatch: %s declares %s, expected %s.', substr($file->getPathname(), strlen($root) + 1), $match[1], $expectedNamespace);
        }
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $declaration)
            && $declaration[1] !== $file->getBasename('.php')) {
            $errors[] = sprintf('Class/file case mismatch: %s declares %s.', substr($file->getPathname(), strlen($root) + 1), $declaration[1]);
        }
    }
}

$stalePatterns = [
    'vtinnovations/contao-language-flow',
    'contao-language-flow',
    'contao_language_flow',
    'Contao Language Flow',
    'Vtinnovations\\ContaoLanguageFlow',
    'ContaoLanguageFlowBundle',
];
$scanDirectories = ['src', 'contao', 'tests', 'tools', '.github'];
foreach ($scanDirectories as $directory) {
    if (!is_dir($root.'/'.$directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        // A file may keep a former identifier only when it declares why, using
        // the marker below. Intentional cases are legacy migration compatibility
        // and historical changelog references; everything else is a defect.
        $intentional = str_contains($contents, '@legacy-identity-reference');
        // This validator necessarily contains the forbidden spellings it seeks.
        if ($file->getPathname() !== __FILE__ && !$intentional) {
            foreach ($stalePatterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $errors[] = sprintf('Stale active identity "%s" in %s.', $pattern, substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        $localDevelopmentPrefix = '/Users/'.'admin'.'istrator/Sites/';
        if (str_contains($contents, $localDevelopmentPrefix)) {
            $errors[] = sprintf('Absolute local development path in %s.', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($errors)).PHP_EOL);
    exit(1);
}

printf("Identity, stale-name and PSR-4 case checks passed for %s (%s).\n", $expected['name'], $expected['identifier']);
