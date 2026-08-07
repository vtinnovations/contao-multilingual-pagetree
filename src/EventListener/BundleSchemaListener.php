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

namespace Vtinnovations\ContaoMultilingualPagetree\EventListener;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Vtinnovations\ContaoMultilingualPagetree\Schema\BundleSchema;

/** Adds bundle-owned, explicitly named indexes to Contao's expected schema. */
final class BundleSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $this->augmentSchema($event->getSchema());
    }

    public function augmentSchema(Schema $schema): void
    {
        foreach (BundleSchema::namedIndexes() as $definition) {
            if (!$schema->hasTable($definition['table'])) {
                continue;
            }

            $table = $schema->getTable($definition['table']);

            if ($table->hasIndex($definition['name'])) {
                continue;
            }

            foreach ($definition['columns'] as $column) {
                if (!$table->hasColumn($column)) {
                    continue 2;
                }
            }

            if ($definition['unique']) {
                $table->addUniqueIndex($definition['columns'], $definition['name']);
            } else {
                $table->addIndex($definition['columns'], $definition['name']);
            }
        }
    }
}
