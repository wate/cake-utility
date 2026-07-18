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
 *
 * ScenarioLoader によるYAMLシナリオの投入・削除・参照解決を検証する。
 */
class ScenarioLoaderTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = [];

    /**
     * データベース接続
     *
     * @var \Cake\Database\Connection|null
     */
    protected $connection = null;

    /**
     * テスト前処理
     *
     * テストデータのクリアとテーブルスキーマ定義のセットアップを行う。
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        // bootstrap.php ですでに test 接続とスキーマは作成済み
        // テスト間の分離のためデータをクリア
        $connection = ConnectionManager::get('test');
        $tables = ['shop_products', 'products', 'shops', 'profiles', 'users', 'groups', 'import_departments', 'import_employees'];
        foreach ($tables as $table) {
            $connection->execute("DELETE FROM {$table}");
        }

        $this->setupTableDefinitions();
    }

    /**
     * テスト後処理
     *
     * 全テーブルのテストデータとTableLocatorキャッシュをクリアする。
     *
     * @return void
     */
    public function tearDown(): void
    {
        // Clear data from tables to ensure isolation between tests
        $tables = ['shop_products', 'products', 'shops', 'profiles', 'users', 'groups', 'import_departments', 'import_employees'];
        $connection = ConnectionManager::get('test');
        foreach ($tables as $table) {
            $connection->execute("DELETE FROM {$table}");
        }

        // Clear TableLocator cache
        TableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * テーブルスキーマ定義をセットアップする。
     *
     * SQLiteではJSON/Boolean型が正しく認識されないため、明示的にカラム型を定義する。
     *
     * @return void
     */
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
            $table = TableRegistry::getTableLocator()->get($alias, ['connectionName' => 'test']);
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

    /**
     * YAMLシナリオのロードと参照解決（ref:ラベルの解決、型キャスト）を検証する。
     *
     * @return void
     */
    public function testScenarioLoadingWithRefResolution(): void
    {
        $tableLocator = TableRegistry::getTableLocator();
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data';
        $loader = new ScenarioLoader($baseDir, $tableLocator, 'test');

        $result = $loader->load('scenario-loader');

        $this->assertArrayHasKey('records_inserted', $result);
        $this->assertArrayHasKey('records_updated', $result);
        $this->assertGreaterThan(0, $result['records_inserted']);

        $shopProductsTable = TableRegistry::getTableLocator()->get('CakeUtility.ShopProducts', ['connectionName' => 'test']);
        $shopProduct = $shopProductsTable->find()->firstOrFail();
        $this->assertIsBool($shopProduct->is_active, 'is_active should be cast to a PHP boolean');
        $this->assertIsArray($shopProduct->meta_json, 'meta_json should be decoded to a PHP array');
        $this->assertArrayHasKey('category', $shopProduct->meta_json);
    }

    /**
     * 初回ロードで挿入、2回目ロードで更新（upsert）が正しくカウントされることを検証する。
     *
     * @return void
     */
    public function testInsertAndUpdateCounting(): void
    {
        $tableLocator = TableRegistry::getTableLocator();
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data';
        $loader = new ScenarioLoader($baseDir, $tableLocator, 'test');

        $result1 = $loader->load('scenario-loader', 'groups');
        $firstInsertCount = $result1['records_inserted'];
        $firstUpdateCount = $result1['records_updated'];
        $this->assertGreaterThan(0, $firstInsertCount, 'First load should insert records');
        $this->assertEquals(0, $firstUpdateCount, 'First load should not update any records');

        $result2 = $loader->load('scenario-loader', 'groups');
        $secondInsertCount = $result2['records_inserted'];
        $secondUpdateCount = $result2['records_updated'];
        $this->assertEquals(0, $secondInsertCount);
        $this->assertGreaterThan(0, $secondUpdateCount);
    }

    /**
     * ロードしたシナリオをクリアすると、削除件数が正しく返され、
     * テーブルが空になることを検証する。
     *
     * @return void
     */
    public function testScenarioClear(): void
    {
        $tableLocator = TableRegistry::getTableLocator();
        $baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data';
        $loader = new ScenarioLoader($baseDir, $tableLocator, 'test');

        $result = $loader->load('scenario-loader', 'groups');
        $inserted = $result['records_inserted'];
        $this->assertGreaterThan(0, $inserted, 'Records should be inserted');

        // Verify data exists
        $groupsTable = TableRegistry::getTableLocator()->get('CakeUtility.Groups', ['connectionName' => 'test']);
        $countBefore = $groupsTable->find()->count();
        $this->assertGreaterThan(0, $countBefore);

        $deleted = $loader->clear('scenario-loader', 'groups');
        $this->assertEquals($inserted, $deleted);

        $countAfter = $groupsTable->find()->count();
        $this->assertEquals(0, $countAfter);
    }

    /**
     * 存在しない ref: ラベルを参照すると RuntimeException がスローされることを検証する。
     *
     * @return void
     */
    public function testInvalidRefThrowsException(): void
    {
        $tableLocator = TableRegistry::getTableLocator();
        $tmpDir = sys_get_temp_dir() . '/scenario_' . uniqid();
        mkdir($tmpDir);
        $tmpFile = $tmpDir . '/users.yml';
        file_put_contents($tmpFile, "- _ref: user_bad\n  group_id: 'ref:non_existent_group'");

        $tmpParentDir = dirname($tmpDir);
        $scenarioName = basename($tmpDir);
        $loader = new ScenarioLoader($tmpParentDir, $tableLocator, 'test');

        try {
            $loader->load($scenarioName);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reference "non_existent_group" not found', $e->getMessage());
        } finally {
            unlink($tmpFile);
            rmdir($tmpDir);
        }
    }
}
