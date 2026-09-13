<?php

declare(strict_types=1);

namespace OCA\Daytracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260817233000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('daytracker_categories')) {
            $table = $schema->createTable('daytracker_categories');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('sort_order', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'dt_cat_user_idx');
            $table->addUniqueIndex(['user_id', 'name'], 'dt_cat_user_name_uidx');
        }

        if (!$schema->hasTable('daytracker_options')) {
            $table = $schema->createTable('daytracker_options');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('category_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('label', 'string', [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('sort_order', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['category_id'], 'dt_opt_category_idx');
            $table->addUniqueIndex(['category_id', 'label'], 'dt_opt_category_label_uidx');
        }

        if (!$schema->hasTable('daytracker_entries')) {
            $table = $schema->createTable('daytracker_entries');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('entry_date', 'string', [
                'notnull' => true,
                'length' => 10,
            ]);
            $table->addColumn('category_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('option_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'string', [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'entry_date'], 'dt_ent_user_date_idx');
            $table->addUniqueIndex(['user_id', 'entry_date', 'category_id'], 'dt_ent_unique_user_date_cat');
        }

        return $schema;
    }
}
