<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Yaml;

use Cake\TestSuite\TestCase;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\TableRegistry;
use Cake\Datasource\ConnectionManager;
use CakeUtility\Yaml\ScenarioLoader;
use Cake\Database\TypeFactory;

/**
 * ScenarioLoaderTest
 */
class ScenarioLoaderTest extends TestCase
{
    protected array $fixtures = [];

    protected $connection = null;

    public function setUp(): void
    {
        parent::setUp();

        // Ensure bootstrap.php (which sets up SQLite) has been loaded
        // __DIR__ = tests/TestCase/Yaml
        // dirname(__DIR__, 2) = tests
        $dbPath = dirname(__DIR__, 2) . '/test_scenario.sqlite';

        // Always re-create connection to ensure it uses SQLite
        if (in_array('test_scenario', ConnectionManager::configured())) {
            ConnectionManager::drop('test_scenario');
        }
        ConnectionManager::setConfig('test_scenario', [
            'className' => 'Cake\Database\Connection',
            'driver' => 'Cake\Database\Driver\Sqlite',
            'database' => $dbPath,
            'encoding' => 'utf8',
            'cacheMetadata' => false,
        ]);

        // Apply schema (drop tables first to avoid conflicts)
        $connection = ConnectionManager::get('test_scenario');
        $schemaFile = dirname(__DIR__, 2) . '/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $statement) {
                if (stripos($statement, 'CREATE TABLE') !== false) {
                    $tableName = '';
                    if (preg_match('/CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([\w]+)/i', $statement, $matches)) {
                        $tableName = $matches[1];
                    }
                    if ($tableName) {
                        $connection->execute("DROP TABLE IF EXISTS {$tableName}");
                    }
                }
                $connection->execute($statement);
            }
        }

        $this->setupTableDefinitions();
    }

    public function tearDown(): void
    {
        // Clear data from tables to ensure isolation between tests
        $tables = ['shop_products', 'products', 'shops', 'profiles', 'users', 'groups'];
        $connection = ConnectionManager::get('test_scenario');
        foreach ($tables as $table) {
            $connection->execute("DELETE FROM {$table}");
        }

        // Clear TableLocator cache
        TableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * Create a TableLocator that uses the 'test_scenario' connection.
     *
     * @return \Cake\ORM\Locator\TableLocator
     */
    protected function createSqliteTableLocator(): TableLocator
    {
        return TableRegistry::getTableLocator();
    }

    protected function setupTableDefinitions(): void
    {
        // Explicitly define column types for tables to ensure the ORM handles
        // JSON and Boolean types correctly, as SQLite may report them as TEXT/INTEGER.

        $tableDefinitions = [
            'CakeUtility.Users' => [
                'birthday' => 'date',
            ],
            'CakeUtility.ShopProducts' => [
                'is_active' => 'boolean',
                'meta_json' => 'json',
            ],
        ];

        foreach ($tableDefinitions as $alias => $columns) {
            $table = TableRegistry::getTableLocator()->get($alias, ['connectionName' => 'test_scenario']);
            $schema = $table->getSchema();
            foreach ($columns as $column => $type) {
                if (!$schema->hasColumn($column)) {
                    $schema->addColumn($column, ['type' => $type]);
                } else {
                    $schema->setColumnType($column, $type);
                }
            }
        }

        // Register JSON type to handle array serialization in SQLite
        TypeFactory::set('json', new \Cake\Database\Type\JsonType());
    }

    public function testScenarioLoadingWithRefResolution(): void
    {
        $tableLocator = $this->createSqliteTableLocator();
        $loader = new ScenarioLoader($tableLocator, 'test_scenario');
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data/scenario-loader';

        // Load scenario files in dependency order
        $files = [
            'groups.yml' => 'CakeUtility.Groups',
            'users.yml' => 'CakeUtility.Users',
            'profiles.yml' => 'CakeUtility.Profiles',
            'shops.yml' => 'CakeUtility.Shops',
            'products.yml' => 'CakeUtility.Products',
            'shop_products.yml' => 'CakeUtility.ShopProducts',
        ];

        $totalInserted = 0;
        foreach ($files as $file => $table) {
            $result = $loader->load($baseDir . '/' . $file, $table);
            $totalInserted += $result['records_inserted'];
            $this->assertArrayHasKey('records_inserted', $result, 'load() should return records_inserted count');
            $this->assertArrayHasKey('records_updated', $result, 'load() should return records_updated count');
            echo "\nFile: {$file} | Inserted: {$result['records_inserted']} | Updated: {$result['records_updated']}";
        }

        $this->assertGreaterThan(0, $totalInserted, 'At least some records should be inserted');

        // Verify boolean/json columns are actually stored and read back as their declared types,
        // not just as the raw integer/text SQLite infers for those columns.
        $shopProductsTable = TableRegistry::getTableLocator()->get('CakeUtility.ShopProducts', ['connectionName' => 'test_scenario']);
        $shopProduct = $shopProductsTable->find()->firstOrFail();
        $this->assertIsBool($shopProduct->is_active, 'is_active should be cast to a PHP boolean');
        $this->assertIsArray($shopProduct->meta_json, 'meta_json should be decoded to a PHP array');
        $this->assertArrayHasKey('category', $shopProduct->meta_json);
    }

    public function testInsertAndUpdateCounting(): void
    {
        $tableLocator = $this->createSqliteTableLocator();
        $loader = new ScenarioLoader($tableLocator, 'test_scenario');
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data/scenario-loader';

        // First load: all inserts
        $result1 = $loader->load($baseDir . '/groups.yml', 'CakeUtility.Groups');
        $firstInsertCount = $result1['records_inserted'];
        $firstUpdateCount = $result1['records_updated'];
        $this->assertGreaterThan(0, $firstInsertCount, 'First load should insert records');
        $this->assertEquals(0, $firstUpdateCount, 'First load should not update any records');

        // Second load (idempotent): all updates (same data)
        $result2 = $loader->load($baseDir . '/groups.yml', 'CakeUtility.Groups');
        $secondInsertCount = $result2['records_inserted'];
        $secondUpdateCount = $result2['records_updated'];
        $this->assertEquals(0, $secondInsertCount, 'Second load should not insert duplicate records');
        $this->assertGreaterThan(0, $secondUpdateCount, 'Second load should update existing records');
    }

    public function testScenarioClear(): void
    {
        $tableLocator = $this->createSqliteTableLocator();
        $loader = new ScenarioLoader($tableLocator, 'test_scenario');
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data/scenario-loader';

        // Load first
        $result = $loader->load($baseDir . '/groups.yml', 'CakeUtility.Groups');
        $inserted = $result['records_inserted'];
        $this->assertGreaterThan(0, $inserted, 'Records should be inserted');

        // Verify data exists
        $groupsTable = TableRegistry::getTableLocator()->get('CakeUtility.Groups');
        $countBefore = $groupsTable->find()->count();
        $this->assertGreaterThan(0, $countBefore, 'Groups table should have data');

        // Clear
        $deleted = $loader->clear($baseDir . '/groups.yml', 'CakeUtility.Groups');
        $this->assertEquals($inserted, $deleted, 'Clear should delete same number of records as inserted');

        // Verify data is deleted
        $countAfter = $groupsTable->find()->count();
        $this->assertEquals(0, $countAfter, 'Groups table should be empty after clear');
    }

    public function testInvalidRefThrowsException(): void
    {
        $tableLocator = $this->createSqliteTableLocator();
        $loader = new ScenarioLoader($tableLocator, 'test_scenario');
        $tmpFile = tempnam(sys_get_temp_dir(), 'scenario_err');
        file_put_contents($tmpFile, "- _ref: user_bad\n  group_id: 'ref:non_existent_group'");

        try {
            $loader->load($tmpFile, 'CakeUtility.Users');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reference "non_existent_group" not found', $e->getMessage());
        } finally {
            unlink($tmpFile);
        }
    }
}
