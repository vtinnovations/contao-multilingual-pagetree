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
 * Loads the built artefact's service definitions and checks that the definition
 * graph still resolves after the private symbols were renamed.
 *
 * It does not boot Contao: it loads the artefact's `services.yaml` into a plain
 * container builder, resolves every class through an autoloader that points at
 * the artefact (never at the readable source tree) and verifies that every
 * referenced service is either defined there or is a known framework service.
 *
 * Usage: php tools/ci/compile-artefact-container.php <artefact-directory>
 */

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

$artefact = rtrim((string) ($argv[1] ?? ''), '/');

if ('' === $artefact || !is_dir($artefact.'/src')) {
    fwrite(STDERR, "Usage: php tools/ci/compile-artefact-container.php <artefact-directory>\n");

    exit(1);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Prepended so the artefact wins over the identically namespaced source tree.
spl_autoload_register(static function (string $class) use ($artefact): void {
    $prefix = 'Vtinnovations\\ContaoMultilingualPagetree\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $artefact.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

/** Services provided by Contao or Symfony, not by this bundle. */
$external = [
    'database_connection', 'request_stack', 'router', 'contao.framework', 'contao.csrf.token_manager',
    'contao.routing.page_registry', 'contao.routing.page_candidates', 'contao.routing.route_provider',
    'contao.security.token_checker', 'fos_http_cache.cache_manager', 'logger', 'translator', 'security.helper',
];

$container = new ContainerBuilder();
$container->setParameter('kernel.project_dir', sys_get_temp_dir().'/artefact-project');
$container->setParameter('contao.csrf_token_name', 'contao_csrf_token');

(new YamlFileLoader($container, new FileLocator($artefact.'/src/Resources/config')))->load('services.yaml');

$failures = [];
$definitions = $container->getDefinitions();

foreach ($definitions as $id => $definition) {
    $class = $definition->getClass() ?? $id;

    if (is_string($class) && str_starts_with($class, 'Vtinnovations\\') && !class_exists($class) && !interface_exists($class)) {
        $failures[] = 'Definition points at a class the artefact does not provide: '.$class;
    }

    foreach ($definition->getArguments() as $argument) {
        if (!$argument instanceof Reference) {
            continue;
        }

        $target = (string) $argument;

        if (isset($definitions[$target]) || $container->hasAlias($target) || in_array($target, $external, true)) {
            continue;
        }

        if (str_starts_with($target, 'monolog.logger.') || str_starts_with($target, '.inner')) {
            continue;
        }

        $failures[] = sprintf('Service "%s" references an unknown service "%s".', $id, $target);
    }
}

foreach ($container->getAliases() as $alias => $target) {
    if (!isset($definitions[(string) $target]) && !in_array((string) $target, $external, true)) {
        $failures[] = sprintf('Alias "%s" points at the unknown service "%s".', $alias, (string) $target);
    }
}

if ([] !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($failures)).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, sprintf("Artefact service graph resolved: %d definitions, %d aliases.\n", count($definitions), count($container->getAliases())));
