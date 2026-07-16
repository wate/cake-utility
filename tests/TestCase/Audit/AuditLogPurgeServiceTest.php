<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Audit;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\TableLocator;
use Cake\TestSuite\TestCase;
use CakeUtility\Audit\AuditLogPurgeService;

/**
 * AuditLogPurgeServiceTest
 *
 * 保持期間超過レコードのCSV出力→DB削除→古いCSV削除を検証する。
 */
class AuditLogPurgeServiceTest extends TestCase
{
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
            'retentionByCategory' => [
                'auth' => [
                    'login' => 30,
                ],
            ],
            'csvExportPath' => $this->csvExportDir . '/',
            'csvRetentionDays' => 365,
            'csvRetentionByCategory' => [
                'auth' => [
                    '__default__' => 180,
                ],
            ],
        ]);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        // Clean up test data
        $connection = ConnectionManager::get('test');
        $connection->execute('DELETE FROM audit_logs');

        // Remove CSV export dir
        $this->removeDirectory($this->csvExportDir);

        Configure::delete('AuditLog');
    }

    /**
     * 保持期間超過レコードがCSV出力され、DBから削除されることを確認する。
     *
     * @return void
     */
    public function testPurgeExportsAndDeletesExpiredRecords(): void
    {
        $this->insertTestRecords('auth', 'login', '-40 days');
        $this->insertTestRecords('auth', 'login', '-20 days');

        // auth/login は30日保持のため、-40日のレコードのみ対象
        $service = new AuditLogPurgeService('test');
        $result = $service->purge();

        // -40日分がCSV出力・削除されている
        $this->assertEquals(1, $result['exported']);
        $this->assertEquals(1, $result['purged']);

        // DBに残っているのは-20日分のみ
        $connection = ConnectionManager::get('test');
        $count = $connection->execute(
            "SELECT COUNT(*) as cnt FROM audit_logs WHERE category = 'auth' AND action = 'login'"
        )->fetch('assoc');

        $this->assertEquals(1, $count['cnt']);

        // CSVファイルが作成されている（ファイル名は実行日の日付）
        $today = date('Y-m-d');
        $filename = sprintf('audit_logs_auth_%s.csv', $today);
        $this->assertFileExists($this->csvExportDir . '/' . $filename);
    }

    /**
     * デフォルト保持期間（90日）が適用され、カテゴリー個別設定が優先されることを確認する。
     *
     * @return void
     */
    public function testPurgeRespectsRetentionByCategory(): void
    {
        // auth/login: 30日設定 → -40日で対象
        $this->insertTestRecords('auth', 'login', '-40 days');
        // Users/create: デフォルト90日 → -40日では対象外
        $this->insertTestRecords('Users', 'create', '-40 days');
        // Users/create: デフォルト90日 → -100日で対象
        $this->insertTestRecords('Users', 'create', '-100 days');

        $service = new AuditLogPurgeService('test');
        $result = $service->purge();

        // auth/login の1件 + Users/create の1件 = 2件出力・削除
        $this->assertEquals(2, $result['exported']);
        $this->assertEquals(2, $result['purged']);

        // Users/create の -40日は残る
        $connection = ConnectionManager::get('test');
        $count = $connection->execute(
            "SELECT COUNT(*) as cnt FROM audit_logs WHERE category = 'Users' AND action = 'create'"
        )->fetch('assoc');

        $this->assertEquals(1, $count['cnt']);
    }

    /**
     * パージ対象が存在しない場合、空の結果が返ることを確認する。
     *
     * @return void
     */
    public function testPurgeWithNoExpiredRecords(): void
    {
        $this->insertTestRecords('auth', 'login', '-1 days');

        $service = new AuditLogPurgeService('test');
        $result = $service->purge();

        $this->assertEquals(0, $result['exported']);
        $this->assertEquals(0, $result['purged']);
    }

    /**
     * 古いCSVファイルが自動削除されることを確認する。
     *
     * @return void
     */
    public function testCleanupOldCsvFiles(): void
    {
        // Create old CSV file (超過)
        $oldDate = date('Y-m-d', strtotime('-400 days'));
        $oldFile = $this->csvExportDir . '/' . sprintf('audit_logs_auth_%s.csv', $oldDate);
        file_put_contents($oldFile, 'dummy');

        // Create recent CSV file (保持期間内)
        $recentDate = date('Y-m-d', strtotime('-30 days'));
        $recentFile = $this->csvExportDir . '/' . sprintf('audit_logs_Users_%s.csv', $recentDate);
        file_put_contents($recentFile, 'dummy');

        // auth CSVは180日保持、Usersは365日保持
        // -400日は両方超過だが、authはcategory別設定で180日、Usersはデフォルト365日
        // 実際にはauthの-400日のみ削除される（Users-30日は365日内）

        // Insert a record to trigger purge
        $this->insertTestRecords('auth', 'login', '-40 days');

        $service = new AuditLogPurgeService('test');
        $service->purge();

        // authの古いCSVは削除されている
        $this->assertFileDoesNotExist($oldFile);
        // UsersのCSVは保持期間内なので残っている
        $this->assertFileExists($recentFile);
    }

    /**
     * ヘルパー: テスト用レコードを挿入する。
     *
     * @param string $category カテゴリー
     * @param string $action アクション
     * @param string $createdFromNow createdに設定する相対日時（例: '-40 days'）
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
