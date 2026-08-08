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

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$errors = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || str_contains($path, '/vendor/') || str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
        continue;
    }

    try {
        if ('json' === $file->getExtension()) {
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } elseif (in_array($file->getExtension(), ['yaml', 'yml'], true)) {
            if (!class_exists(Symfony\Component\Yaml\Yaml::class)) {
                throw new RuntimeException('Symfony Yaml is unavailable. Run after Composer dependencies are installed.');
            }
            Symfony\Component\Yaml\Yaml::parseFile($path, Symfony\Component\Yaml\Yaml::PARSE_CUSTOM_TAGS);
        } elseif ('xlf' === $file->getExtension() || 'xliff' === $file->getExtension()) {
            $document = new DOMDocument();
            if (!$document->load($path, LIBXML_NONET)) {
                throw new RuntimeException('Invalid XML.');
            }
        }
    } catch (Throwable $exception) {
        $errors[] = sprintf('%s: %s', substr($path, strlen($root) + 1), $exception->getMessage());
    }
}

if ([] !== $errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "JSON, YAML and XLIFF configuration validation passed.\n");
