<?php

declare(strict_types=1);

namespace OCA\Daytracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1110Date20260819151000 extends SimpleMigrationStep
{
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('daytracker_categories')) {
            $output->warning(
                'Die Tabelle daytracker_categories existiert nicht. '
                . 'Die Spalte dashboard_limit wurde nicht angelegt.'
            );

            return null;
        }

        $table = $schema->getTable('daytracker_categories');

        if ($table->hasColumn('dashboard_limit')) {
            $output->info(
                'Die Spalte dashboard_limit existiert bereits. '
                . 'Es ist keine Schemaänderung erforderlich.'
            );

            return null;
        }

        $table->addColumn('dashboard_limit', 'integer', [
            'notnull' => true,
            'default' => 2,
        ]);

        $output->info(
            'Die Spalte dashboard_limit wurde zur Tabelle '
            . 'daytracker_categories hinzugefügt.'
        );

        return $schema;
    }
}
