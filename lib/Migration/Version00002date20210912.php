<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version00002date20210912 extends SimpleMigrationStep
{
    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('ncdownloader_info');
        if (!$table->hasColumn('speed')) {
            $table->addColumn('speed', 'string', [
                'notnull' => true,
                'length' => 255,
                'default' => 'unknown',
            ]);
        }
        if (!$table->hasColumn('progress')) {
            $table->addColumn('progress', 'string', [
                'notnull' => true,
                'length' => 255,
                'default' => '0',
            ]);
        }
        if (!$table->hasColumn('filesize')) {
            $table->addColumn('filesize', 'string', [
                'notnull' => false,
                'length' => 255,
                'default' => '',
            ]);
        }
        if (!$table->hasIndex('gid_index')) {
            $table->addUniqueIndex(['gid'], 'gid_index');
        }
        return $schema;
    }
}
