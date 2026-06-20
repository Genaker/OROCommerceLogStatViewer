<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

/**
 * Initial schema installer for the Genaker ComiVoyager bundle.
 */
class GenakerComiVoyagerBundleInstaller implements Installation
{
    public function getMigrationVersion(): string
    {
        return 'v1_0';
    }

    public function up(Schema $schema, QueryBag $queries): void
    {
        $this->createGeocodeCacheTable($schema);
    }

    private function createGeocodeCacheTable(Schema $schema): void
    {
        if ($schema->hasTable('genaker_comivoyager_geocode_cache')) {
            return;
        }

        $table = $schema->createTable('genaker_comivoyager_geocode_cache');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $table->addColumn('address_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('address_text', 'text', ['notnull' => true]);
        $table->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 6, 'notnull' => true]);
        $table->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 6, 'notnull' => true]);
        $table->addColumn('provider', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);

        $table->addUniqueIndex(['address_hash'], 'uniq_genaker_cv_geocode_hash');
        $table->addIndex(['created_at'], 'idx_genaker_cv_geocode_created_at');
    }
}
