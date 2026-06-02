<?php

declare(strict_types=1);

namespace Genaker\Bundle\LogViewerBundle\Migrations\Schema\v1_4;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Adds suggestion and analysis_data columns to genaker_sql_issue.
 *
 * suggestion:    Human-readable actionable recommendation (cache, batch, index).
 * analysis_data: Denormalized JSON with per-request statistics and optional
 *                EXPLAIN plan extracted for slow queries.
 */
class AddSuggestionColumns implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('genaker_sql_issue');

        if (!$table->hasColumn('suggestion')) {
            $table->addColumn('suggestion', 'text', [
                'notnull' => false,
                'comment' => 'Actionable recommendation derived from query analysis',
            ]);
        }

        if (!$table->hasColumn('analysis_data')) {
            $table->addColumn('analysis_data', 'json', [
                'notnull' => false,
                'comment' => 'Denormalized stats: uniqueParamSets, avgMs, minMs, sameParamsRepeats, explainPlan',
            ]);
        }
    }
}
