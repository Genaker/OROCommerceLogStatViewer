<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Schema\v1_5;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class CreateLogEntryTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if ($schema->hasTable('genaker_log_entry')) {
            return;
        }

        $table = $schema->createTable('genaker_log_entry');

        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->addColumn('channel', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('level', 'smallint', ['notnull' => true]);
        $table->addColumn('level_name', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('message', 'text', ['notnull' => true]);
        $table->addColumn('context', 'json', ['notnull' => false]);
        $table->addColumn('extra', 'json', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);
        $table->addColumn('url', 'string', ['length' => 2000, 'notnull' => false]);
        $table->addColumn('ip', 'string', ['length' => 45, 'notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['channel'], 'idx_log_entry_channel');
        $table->addIndex(['level'], 'idx_log_entry_level');
        $table->addIndex(['created_at'], 'idx_log_entry_created');
    }
}
