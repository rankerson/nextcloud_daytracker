<?php

declare(strict_types=1);

namespace OCA\Daytracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version3000Date20260821200600 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options,
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('daytracker_categories')) {
            $output->warning(
                'Die Tabelle daytracker_categories existiert nicht. '
                . 'Die Spalte dashboard_enabled wurde nicht angelegt.'
            );
            return null;
        }

        $table = $schema->getTable('daytracker_categories');
        if ($table->hasColumn('dashboard_enabled')) {
            $output->info(
                'Die Spalte dashboard_enabled existiert bereits. '
                . 'Es ist keine Schemaänderung erforderlich.'
            );
            return null;
        }

        $table->addColumn('dashboard_enabled', 'boolean', [
            'notnull' => true,
            'default' => true,
        ]);

        $output->info(
            'Die Spalte dashboard_enabled wurde zur Tabelle '
            . 'daytracker_categories hinzugefügt.'
        );

        return $schema;
    }
}
