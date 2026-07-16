<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Audit;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakeUtility\Audit\AuditLogWriter;

/**
 * AuditLogWriterTest
 *
 * アプリケーションの test データベース接続を使用し、
 * プラグインのマイグレーションで作成した audit_logs テーブルに対してテストする。
 */
class AuditLogWriterTest extends TestCase
{
    private AuditLogWriter $writer;

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
     * SQLiteの場合は schema.sql ですでに作成済み、MySQLの場合はマイグレーションを実行する。
     *
     * @return void
     */
    private static function ensureTestTables(): void
    {
        try {
            $connection = ConnectionManager::get('test');
            $driver = $connection->getDriver();

            // SQLiteの場合は schema.sql ですでに作成済みのためスキップ
            if ($driver instanceof \Cake\Database\Driver\Sqlite) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        // MySQLの場合はマイグレーションを実行
        $appDir = dirname(__DIR__, 5);
        $command = sprintf(
            'cd %s && bin/cake migrations migrate --plugin CakeUtility --connection test 2>&1',
            escapeshellarg($appDir)
        );
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Migration failed: ' . implode("\n", $output)
            );
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        Configure::write('AuditLog', [
            'retentionDays' => 90,
            'retentionByCategory' => [],
            'csvExportPath' => '/tmp/audit/',
            'csvRetentionDays' => 365,
            'csvRetentionByCategory' => [],
        ]);

        $this->writer = new AuditLogWriter(null, 'test');
    }

    public function tearDown(): void
    {
        parent::tearDown();

        // Clean up all records after each test
        $connection = ConnectionManager::get('test');
        $connection->execute('DELETE FROM audit_logs');

        Configure::delete('AuditLog');
    }

    /**
     * 全カラムを指定して log() を呼び出したとき、正しくINSERTされることを確認する。
     * 戻り値のエンティティと実際のDB行の両方を検証する。
     *
     * @return void
     */
    public function testLogWithAllFields(): void
    {
        $result = $this->writer->log([
            'user_id' => 1,
            'category' => 'Users',
            'action' => 'create',
            'target_id' => '42',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'TestAgent/1.0',
            'context' => ['before' => [], 'after' => ['name' => 'test']],
            'created' => '2026-07-16 12:00:00',
        ]);

        $this->assertNotFalse($result, 'INSERT should succeed');
        $this->assertSame(1, $result->get('user_id'));
        $this->assertSame('Users', $result->get('category'));
        $this->assertSame('create', $result->get('action'));
        $this->assertSame('42', $result->get('target_id'));

        // Verify row exists in DB
        $connection = ConnectionManager::get('test');
        $row = $connection->execute(
            'SELECT * FROM audit_logs WHERE id = ?',
            [$result->get('id')]
        )->fetch('assoc');

        $this->assertNotEmpty($row);
        $this->assertSame('Users', $row['category']);
        $this->assertSame('create', $row['action']);
    }

    /**
     * 最小限のカラム（category, action）のみで log() を呼び出したとき、
     * 省略したカラムが null でINSERTされることを確認する。
     *
     * @return void
     */
    public function testLogWithMinimalFields(): void
    {
        $result = $this->writer->log([
            'category' => 'auth',
            'action' => 'login',
            'created' => '2026-07-16 12:00:00',
        ]);

        $this->assertNotFalse($result);
        $this->assertSame('auth', $result->get('category'));
        $this->assertSame('login', $result->get('action'));
        $this->assertNull($result->get('user_id'));
        $this->assertNull($result->get('target_id'));
        $this->assertNull($result->get('ip_address'));
    }

    /**
     * context 配列が JSON 文字列としてDBに保存されることを確認する。
     * ネストされた配列も正しくシリアライズされる。
     *
     * @return void
     */
    public function testLogSerializesContextAsJson(): void
    {
        $result = $this->writer->log([
            'category' => 'System',
            'action' => 'update',
            'context' => ['key' => 'value', 'nested' => ['a' => 1]],
            'created' => '2026-07-16 12:00:00',
        ]);

        $this->assertNotFalse($result);

        $connection = ConnectionManager::get('test');
        $row = $connection->execute(
            'SELECT * FROM audit_logs WHERE id = ?',
            [$result->get('id')]
        )->fetch('assoc');

        $this->assertNotEmpty($row);
        $this->assertStringContainsString('"key":"value"', $row['context']);
    }

    /**
     * 設定未指定時のデフォルト保持日数が 90 日であることを確認する。
     *
     * @return void
     */
    public function testRetentionDaysDefault(): void
    {
        Configure::delete('AuditLog');

        $days = $this->writer->getRetentionDays();
        $this->assertSame(90, $days);
    }

    /**
     * Configure で保持日数を変更したとき、getRetentionDays() がその値を返すことを確認する。
     *
     * @return void
     */
    public function testRetentionDaysFromConfigure(): void
    {
        Configure::write('AuditLog.retentionDays', 60);

        $days = $this->writer->getRetentionDays();
        $this->assertSame(60, $days);
    }

    /**
     * カテゴリー＋アクションごとの個別保持日数設定が、
     * getRetentionFor() で正しく取得できることを確認する。
     * 未設定の組み合わせはデフォルト値が返る。
     *
     * @return void
     */
    public function testGetRetentionForWithSpecificCategoryAction(): void
    {
        Configure::write('AuditLog.retentionByCategory', [
            'auth' => [
                'login' => 30,
                'logout' => 30,
            ],
        ]);

        $this->assertSame(30, $this->writer->getRetentionFor('auth', 'login'));
        $this->assertSame(90, $this->writer->getRetentionFor('Users', 'create'));
    }
}
