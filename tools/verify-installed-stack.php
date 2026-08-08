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

use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$constraint = getenv('CONTAO_CONSTRAINT') ?: ($argv[1] ?? '');
if (!preg_match('/^(5\.\d+)\.\*$/', $constraint, $match)) {
    fwrite(STDERR, "CONTAO_CONSTRAINT must identify one minor line, for example 5.7.*.\n");
    exit(2);
}

$contao = InstalledVersions::getPrettyVersion('contao/core-bundle') ?? '';
$symfony = InstalledVersions::getPrettyVersion('symfony/framework-bundle') ?? '';
$normalizedContao = InstalledVersions::getVersion('contao/core-bundle') ?? '';
if (!str_starts_with($normalizedContao, $match[1].'.')) {
    fwrite(STDERR, sprintf("Resolved Contao %s does not belong to required line %s.\n", $contao, $constraint));
    exit(1);
}

printf("PHP: %s\nRoot package: %s\nContao: %s\nSymfony: %s\nConstraint: %s\nStrategy: %s\n",
    PHP_VERSION,
    InstalledVersions::getRootPackage()['pretty_version'] ?? 'unknown',
    $contao,
    $symfony,
    $constraint,
    getenv('DEPENDENCY_STRATEGY') ?: 'stable',
);
