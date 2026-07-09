<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Command;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Database\TypeFactory;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakeUtility\Command\ScenarioCommand;

/**
 * ScenarioCommandTest
 *
 * ScenarioCommand は内部で `new ScenarioLoader()` (connectionName='default') を直接
 * 生成するため、このテストでは 'default' 接続をテスト用SQLiteに向けて検証する。
 */
class ScenarioCommandTest extends TestCase
{
    protected StubConsoleOutput $out;

    protected StubConsoleOutput $err;

    protected ConsoleIo $io;

    protected string $baseDir;

    public function setUp(): void
    {
        parent::setUp();

        $dbPath = dirname(__DIR__, 2) . '/test_scenario_command.sqlite';
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        if (in_array('default', ConnectionManager::configured(), true)) {
            ConnectionManager::drop('default');
        }
        ConnectionManager::setConfig('default', [
            'className' => 'Cake\Database\Connection',
            'driver' => 'Cake\Database\Driver\Sqlite',
            'database' => $dbPath,
            'encoding' => 'utf8',
            'cacheMetadata' => false,
        ]);

        $connection = ConnectionManager::get('default');
        $schemaFile = dirname(__DIR__, 2) . '/schema.sql';
        $sql = file_get_contents($schemaFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (stripos($statement, 'CREATE TABLE') !== false) {
                if (preg_match('/CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([\w]+)/i', $statement, $matches)) {
                    $connection->execute("DROP TABLE IF EXISTS {$matches[1]}");
                }
            }
            $connection->execute($statement);
        }

        TypeFactory::set('json', new \Cake\Database\Type\JsonType());

        $this->baseDir = dirname(__DIR__, 3) . '/tests/Fixture/data/scenario-command';

        $this->out = new StubConsoleOutput();
        $this->err = new StubConsoleOutput();
        $this->io = new ConsoleIo($this->out, $this->err);
    }

    public function tearDown(): void
    {
        $tables = ['shop_products', 'products', 'shops', 'profiles', 'users', 'groups'];
        $connection = ConnectionManager::get('default');
        foreach ($tables as $table) {
            $connection->execute("DELETE FROM {$table}");
        }
        TableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    /**
     * `bin/cake scenario load` (テーブル名省略、ファイル名からの自動解決) が
     * クラッシュせず動作することを検証する。
     * (修正前は $table=null が ScenarioLoader::load() の非nullable string引数に
     * 渡され TypeError が発生していた)
     */
    public function testLoadWithoutExplicitTableResolvesFromFilename(): void
    {
        $command = new ScenarioCommand();
        $exitCode = $command->run(['load', '--base-dir=' . $this->baseDir], $this->io);

        $this->assertSame(ScenarioCommand::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Total - Inserted: 2, Updated: 0', implode("\n", $this->out->messages()));

        $groupsTable = TableRegistry::getTableLocator()->get('Groups');
        $this->assertSame(2, $groupsTable->find()->count());
    }

    /**
     * テーブル名を明示指定した場合の読み込みを検証する。
     */
    public function testLoadWithExplicitTable(): void
    {
        $command = new ScenarioCommand();
        $exitCode = $command->run([
            'load',
            'scenario-command',
            'CakeUtility.Groups',
            '--base-dir=' . dirname($this->baseDir),
        ], $this->io);

        $this->assertSame(ScenarioCommand::CODE_SUCCESS, $exitCode);

        $groupsTable = TableRegistry::getTableLocator()->get('CakeUtility.Groups');
        $this->assertSame(2, $groupsTable->find()->count());
    }

    /**
     * `clear` の削除件数がコマンド出力に正しく反映されることを検証する。
     * (修正前は ScenarioLoader::clear() の戻り値が握りつぶされ常に0件表示だった)
     */
    public function testClearReportsAccurateDeletedCount(): void
    {
        $command = new ScenarioCommand();
        $command->run([
            'load',
            'scenario-command',
            'CakeUtility.Groups',
            '--base-dir=' . dirname($this->baseDir),
        ], $this->io);

        $exitCode = $command->run([
            'clear',
            'scenario-command',
            'CakeUtility.Groups',
            '--base-dir=' . dirname($this->baseDir),
        ], $this->io);

        $this->assertSame(ScenarioCommand::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('Total - Deleted: 2', implode("\n", $this->out->messages()));

        $groupsTable = TableRegistry::getTableLocator()->get('CakeUtility.Groups');
        $this->assertSame(0, $groupsTable->find()->count());
    }

    /**
     * ベースディレクトリが存在しない場合はエラー終了することを検証する。
     */
    public function testMissingBaseDirReturnsError(): void
    {
        $command = new ScenarioCommand();
        $exitCode = $command->run(['load', '--base-dir=' . $this->baseDir . '/does-not-exist'], $this->io);

        $this->assertSame(ScenarioCommand::CODE_ERROR, $exitCode);
    }
}
