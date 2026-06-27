<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Schema\v1_0;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class CreateHttpPerformanceTable implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        if ($schema->hasTable('genaker_http_performance')) {
            return;
        }

        $table = $schema->createTable('genaker_http_performance');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('path', 'string', ['length' => 500, 'notnull' => true]);
        $table->addColumn('type', 'string', ['length' => 10,  'notnull' => true, 'comment' => 'http|cli|mq']);
        $table->addColumn('avg_response_ms', 'float', ['notnull' => true, 'comment' => 'EMA: (old+new)/2']);
        $table->addColumn('min_response_ms', 'float', ['notnull' => true]);
        $table->addColumn('max_response_ms', 'float', ['notnull' => true]);
        $table->addColumn('request_count', 'integer', ['notnull' => true, 'default' => 1]);
        $table->addColumn('last_seen_at', 'datetime', ['notnull' => true]);
        $table->addColumn('last_status_code', 'integer', ['notnull' => false, 'comment' => 'HTTP status; null for cli/mq']);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['path', 'type'], 'uniq_http_perf_path_type');
        $table->addIndex(['type'], 'idx_http_perf_type');
        $table->addIndex(['last_seen_at'], 'idx_http_perf_last_seen');
        $table->addIndex(['avg_response_ms'], 'idx_http_perf_avg');
    }
}
