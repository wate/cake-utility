<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Controller\Component;

use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\TestSuite\TestCase;
use CakeUtility\Controller\Component\AuditLogComponent;

/**
 * AuditLogComponentTest
 *
 * AuditLogComponent のログ記録機能を検証する。
 * ComponentはController依存のため、モックリクエストを使ってテストする。
 */
class AuditLogComponentTest extends TestCase
{
    /**
     * テスト対象のコンポーネントインスタンス
     *
     * @var \CakeUtility\Controller\Component\AuditLogComponent
     */
    private AuditLogComponent $component;

    /**
     * テスト用コントローラ
     *
     * @var \Cake\Controller\Controller
     */
    private Controller $controller;

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

        $appDir = dirname(__DIR__, 6);
        $command = sprintf(
            'cd %s && bin/cake migrations migrate --plugin CakeUtility --connection test 2>&1',
            escapeshellarg($appDir)
        );
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('Migration failed: ' . implode("\n", $output));
        }
    }

    /**
     * テスト前処理
     *
     * AuditLog設定の初期化、モックリクエストの生成、コンポーネントインスタンスの生成を行う。
     *
     * @return void
     */
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

        $request = ServerRequestFactory::fromGlobals()
            ->withEnv('REMOTE_ADDR', '192.168.1.100')
            ->withHeader('User-Agent', 'TestBrowser/1.0');

        $this->controller = new Controller($request);
        $registry = new ComponentRegistry($this->controller);
        $this->component = new AuditLogComponent($registry);
    }

    /**
     * テスト後処理
     *
     * テストデータとAuditLog設定をクリアする。
     *
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();

        // Clean up audit_logs
        $connection = ConnectionManager::get('test');
        $connection->execute('DELETE FROM audit_logs');

        Configure::delete('AuditLog');
    }

    /**
     * 通常のログ記録が動作し、IP/UAが自動取得されることを確認する。
     *
     * @return void
     */
    public function testLogRecordsAuditWithIpAndUserAgent(): void
    {
        $result = $this->component->saveLog('auth', 'login', ['login_id' => 1]);

        $this->assertNotFalse($result);
        $this->assertSame('auth', $result->get('category'));
        $this->assertSame('login', $result->get('action'));

        $connection = ConnectionManager::get('test');
        $row = $connection->execute(
            'SELECT * FROM audit_logs WHERE id = ?',
            [$result->get('id')]
        )->fetch('assoc');

        $this->assertNotEmpty($row);
        $this->assertSame('192.168.1.100', $row['ip_address']);
        $this->assertSame('TestBrowser/1.0', $row['user_agent']);
    }

    /**
     * purge=true を指定してもログ記録自体は正常に動作することを確認する。
     * （パージの実体は Task 5 で実装予定）
     *
     * @return void
     */
    public function testLogWithPurgeTrueStillRecords(): void
    {
        $result = $this->component->saveLog('auth', 'login', ['login_id' => 1], purge: true);

        $this->assertNotFalse($result);
        $this->assertSame('auth', $result->get('category'));
        $this->assertSame('login', $result->get('action'));

        $connection = ConnectionManager::get('test');
        $row = $connection->execute(
            'SELECT * FROM audit_logs WHERE id = ?',
            [$result->get('id')]
        )->fetch('assoc');

        $this->assertNotEmpty($row);
    }

    /**
     * 異なるカテゴリーとアクションの組み合わせで正しく記録されることを確認する。
     *
     * @return void
     */
    public function testLogWithDifferentCategoryAndAction(): void
    {
        $result = $this->component->saveLog('export', 'download', ['file' => 'report.csv']);

        $this->assertNotFalse($result);
        $this->assertSame('export', $result->get('category'));
        $this->assertSame('download', $result->get('action'));
    }

    /**
     * context に任意のデータを渡した場合、JSONとして保存されることを確認する。
     *
     * @return void
     */
    public function testLogWithContextData(): void
    {
        $result = $this->component->saveLog('system', 'config_change', [
            'setting' => 'site_name',
            'old_value' => 'Old',
            'new_value' => 'New',
        ]);

        $this->assertNotFalse($result);

        $connection = ConnectionManager::get('test');
        $row = $connection->execute(
            'SELECT * FROM audit_logs WHERE id = ?',
            [$result->get('id')]
        )->fetch('assoc');

        $this->assertNotEmpty($row);
        $context = json_decode($row['context'], true);
        $this->assertSame('site_name', $context['setting']);
    }
}
