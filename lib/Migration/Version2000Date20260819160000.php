<?php

declare(strict_types=1);

namespace OCA\Daytracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version2000Date20260819160000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('daytracker_timeslices')) {
            $table = $schema->createTable('daytracker_timeslices');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 128]);
            $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'dt_ts_user_idx');
            $table->addUniqueIndex(['user_id', 'name'], 'dt_ts_user_name_uidx');
        }

        if ($schema->hasTable('daytracker_categories')) {
            $table = $schema->getTable('daytracker_categories');
            if (!$table->hasColumn('input_mode')) {
                $table->addColumn('input_mode', 'string', ['notnull' => true, 'length' => 16, 'default' => 'options']);
            }
        }

        if ($schema->hasTable('daytracker_entries')) {
            $table = $schema->getTable('daytracker_entries');
            if (!$table->hasColumn('timeslice_id')) {
                $table->addColumn('timeslice_id', 'integer', ['notnull' => false]);
            }
            if (!$table->hasColumn('text_value')) {
                $table->addColumn('text_value', 'text', ['notnull' => false]);
            }
            if ($table->hasIndex('dt_ent_unique_user_date_cat')) {
                $table->dropIndex('dt_ent_unique_user_date_cat');
            }
            if (!$table->hasIndex('dt_ent_unique_user_date_slice_cat')) {
                $table->addUniqueIndex(['user_id', 'entry_date', 'timeslice_id', 'category_id'], 'dt_ent_unique_user_date_slice_cat');
            }
            $table->changeColumn('option_id', ['notnull' => false]);
        }

        return $schema;
    }
}
