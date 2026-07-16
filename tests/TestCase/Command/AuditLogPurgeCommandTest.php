<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakeUtility\Command\AuditLogPurgeCommand;

/**
 * AuditLogPurgeCommandTest
 *
 * AuditLogPurgeCommand が正しくパージ処理を呼び出すことを確認する。
 */
class AuditLogPurgeCommandTest extends TestCase
{
    private AuditLogPurgeCommand $command;

    private StubConsoleOutput $out;

    private StubConsoleOutput $err;

    private ConsoleIo $io;

    private string $csvExportDir;

    /**
     * テストクラス全体で1度だけテーブルを準備する。
     * プロジェクトルートからの実行時はマイグレーションを流し、
     * プラグイン単体の実行時は bootstrap.php が schema.sql を読み込むため何もしない。
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::ensureTestTables();
    }

    /**
     * テスト用テーブルを確保する。
     *
     * @return void
     */
    private static function ensureTestTables(): void
    {
        try {
            $connection = ConnectionManager::get('test');
            $driver = $connection->getDriver();
            if ($driver instanceof \Cake\Database\Driver\Sqlite) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $appDir = dirname(__DIR__, 5);
        $command = sprintf(
            'cd %s && bin/cake migrations migrate --plugin CakeUtility --connection test 2>&1',
            escapeshellarg($appDir)
        );
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Migration failed: ' . implode("\n", $output));
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->csvExportDir = sys_get_temp_dir() . '/audit_test_' . uniqid();
        mkdir($this->csvExportDir, 0755, true);

        Configure::write('AuditLog', [
            'retentionDays' => 90,
            'csvExportPath' => $this->csvExportDir . '/',
            'csvRetentionDays' => 365,
            'csvRetentionByCategory' => [],
        ]);

        $this->command = new AuditLogPurgeCommand();
        $this->out = new StubConsoleOutput();
        $this->err = new StubConsoleOutput();
        $this->io = new ConsoleIo($this->out, $this->err);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $connection = ConnectionManager::get('test');
        $connection->execute('DELETE FROM audit_logs');

        $this->removeDirectory($this->csvExportDir);

        Configure::delete('AuditLog');
    }

    /**
     * コマンド実行で保持期間超過レコードがパージされることを確認する。
     *
     * @return void
     */
    public function testPurgeCommandRemovesExpiredRecords(): void
    {
        $this->insertTestRecords('auth', 'login', '-100 days');
        $this->insertTestRecords('auth', 'login', '-1 days');

        $exitCode = $this->command->run(['--connection=test'], $this->io);

        $this->assertSame(Command::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('パージ完了', implode("\n", $this->out->messages()));

        // -100日分は削除されている
        $connection = ConnectionManager::get('test');
        $count = $connection->execute(
            "SELECT COUNT(*) as cnt FROM audit_logs WHERE category = 'auth' AND action = 'login'"
        )->fetch('assoc');

        $this->assertEquals(1, $count['cnt']);
    }

    /**
     * パージ対象がない場合もエラーなく終了することを確認する。
     *
     * @return void
     */
    public function testPurgeCommandWithNoExpiredRecords(): void
    {
        $this->insertTestRecords('auth', 'login', '-1 days');

        $exitCode = $this->command->run(['--connection=test'], $this->io);

        $this->assertSame(Command::CODE_SUCCESS, $exitCode);
    }

    /**
     * ヘルパー: テスト用レコードを挿入する。
     *
     * @param string $category カテゴリー
     * @param string $action アクション
     * @param string $createdFromNow createdに設定する相対日時
     * @return void
     */
    private function insertTestRecords(string $category, string $action, string $createdFromNow): void
    {
        $connection = ConnectionManager::get('test');
        $created = date('Y-m-d H:i:s', strtotime($createdFromNow));

        $connection->execute(
            'INSERT INTO audit_logs (category, action, created) VALUES (?, ?, ?)',
            [$category, $action, $created]
        );
    }

    /**
     * ディレクトリを再帰的に削除する。
     *
     * @param string $dir ディレクトリパス
     * @return void
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
