<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Schema\v1_3;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class CreateSqlIssueTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if ($schema->hasTable('genaker_sql_issue')) {
            return;
        }

        $table = $schema->createTable('genaker_sql_issue');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('sql_template', 'text', ['notnull' => true, 'comment' => 'Normalised SQL with params replaced by ?']);
        $table->addColumn('is_n1', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('is_slow', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('worst_n1_count', 'integer', ['notnull' => false, 'comment' => 'Highest repeat count per request']);
        $table->addColumn('worst_slow_ms', 'float', ['notnull' => false, 'comment' => 'Highest single execution time in ms']);
        $table->addColumn('occurrence_count', 'integer', ['notnull' => true, 'default' => 1]);
        $table->addColumn('last_seen_at', 'datetime', ['notnull' => true]);
        $table->addColumn('last_caller', 'string', ['length' => 500, 'notnull' => false]);
        $table->addColumn('last_params', 'json', ['notnull' => false]);
        $table->addColumn('last_url', 'string', ['length' => 1000, 'notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['sql_template'], 'uniq_sql_issue_template');
        $table->addIndex(['is_n1'], 'idx_sql_issue_n1');
        $table->addIndex(['is_slow'], 'idx_sql_issue_slow');
        $table->addIndex(['last_seen_at'], 'idx_sql_issue_last_seen');
        $table->addIndex(['worst_slow_ms'], 'idx_sql_issue_worst_slow');
    }
}
