<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Schema\v1_6;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class AddLogEntryGroupingColumns implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if (!$schema->hasTable('genaker_log_entry')) {
            return;
        }

        $table = $schema->getTable('genaker_log_entry');

        if (!$table->hasColumn('message_key')) {
            $table->addColumn('message_key', 'string', ['length' => 64, 'notnull' => false]);
        }

        if (!$table->hasColumn('occurrence_count')) {
            $table->addColumn('occurrence_count', 'integer', ['notnull' => true, 'default' => 1]);
        }

        if (!$table->hasColumn('first_seen_at')) {
            $table->addColumn('first_seen_at', 'datetime', ['notnull' => false]);
        }

        if (!$table->hasIndex('uniq_log_entry_message_key')) {
            $table->addUniqueIndex(['message_key'], 'uniq_log_entry_message_key');
        }
    }
}
