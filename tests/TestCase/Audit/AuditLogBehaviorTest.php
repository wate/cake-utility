<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Audit;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakeUtility\Audit\AuditLogBehavior;

/**
 * テスト用テーブルクラス（auditLogSanitizeコールバック付き）
 * Behaviorテスト全ケースで共用する。
 */
class TestArticlesTable extends Table
{
    /**
     * サニタイズコールバック
     * password/email をマスキングする。
     *
     * @param string $action アクション種別
     * @param array $before 変更前データ
     * @param array $after 変更後データ
     * @return array サニタイズ後の ['before' => ..., 'after' => ...]
     */
    public function auditLogSanitize(string $action, array $before, array $after): array
    {
        $sensitiveFields = ['password', 'email'];
        foreach ($sensitiveFields as $field) {
            if (isset($before[$field])) {
                $before[$field] = '[REDACTED]';
            }
            if (isset($after[$field])) {
                $after[$field] = '[REDACTED]';
            }
        }

        return ['before' => $before, 'after' => $after];
    }
}

/**
 * AuditLogBehaviorTest
 *
 * AuditLogBehavior によるモデル変更検知と監査ログ自動記録を検証する。
 */
class AuditLogBehaviorTest extends TestCase
{
    /**
     * テストデータベース接続
     *
     * @var \Cake\Database\Connection
     */
    private Connection $connection;

    /**
     * テスト用テーブル（TestArticlesTable）のインスタンス
     *
     * @var \Cake\ORM\Table
     */
    private Table $articlesTable;

    /**
     * テストクラス全体で1度だけテーブルを準備する。
     *
     * プロジェクトルートからの実行時はマイグレーションでテーブルを作成し、
     * プラグイン単体の実行時は bootstrap.php が schema.sql を読み込むため何もしない。
     *
     * @return void
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

    /**
     * テスト前処理
     *
     * AuditLog設定の初期化とテストテーブルのクリーンアップを行う。
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

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $this->connection = $connection;

        // Clean up tables before each test
        $connection->execute('DELETE FROM test_articles');
        $connection->execute('DELETE FROM audit_logs');

        // Register table class with Behavior
        $tableLocator = new TableLocator();
        $this->articlesTable = $tableLocator->get(TestArticlesTable::class, [
            'connection' => $connection,
        ]);
        $this->articlesTable->addBehavior(AuditLogBehavior::class, [
            'connectionName' => 'test',
        ]);
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

        // Clean up
        $this->connection->execute('DELETE FROM test_articles');
        $this->connection->execute('DELETE FROM audit_logs');

        Configure::delete('AuditLog');
    }

    /**
     * エンティティ新規作成時に、audit_logs テーブルに create ログが記録されることを確認する。
     * before は空、after には保存後の値が含まれる。
     *
     * @return void
     */
    public function testCreateRecordsAuditLog(): void
    {
        $article = $this->articlesTable->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body content',
            'author' => 'tester',
            'created' => '2026-07-16 12:00:00',
            'modified' => '2026-07-16 12:00:00',
        ]);

        $saved = $this->articlesTable->save($article);
        $this->assertNotFalse($saved);

        // Verify audit log was created
        $log = $this->connection->execute(
            'SELECT * FROM audit_logs WHERE category = ? AND action = ? ORDER BY id DESC LIMIT 1',
            ['TestArticles', 'create']
        )->fetch('assoc');

        $this->assertNotEmpty($log, 'Audit log should exist for create');
        $this->assertEquals('create', $log['action']);
        $this->assertEquals((string)$saved->get('id'), $log['target_id']);

        // Verify context
        $context = json_decode($log['context'], true);
        $this->assertEmpty($context['before'], 'Create should have empty before');
        $this->assertEquals('Test Article', $context['after']['title']);
    }

    /**
     * エンティティ更新時に、変更前後の差分が audit_logs に正しく記録されることを確認する。
     * 除外カラム（modified）は context に含まれない。
     *
     * @return void
     */
    public function testUpdateRecordsAuditLogWithBeforeAfter(): void
    {
        // Create first
        $article = $this->articlesTable->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'author' => 'tester',
            'created' => '2026-07-16 12:00:00',
            'modified' => '2026-07-16 12:00:00',
        ]);
        $saved = $this->articlesTable->save($article);
        $this->assertNotFalse($saved);
        $articleId = $saved->get('id');

        // Now update
        $article = $this->articlesTable->get($articleId);
        $article->set('title', 'Updated Title');
        $article->set('modified', '2026-07-16 13:00:00');
        $this->articlesTable->save($article);

        // Verify audit log was created for update
        $log = $this->connection->execute(
            'SELECT * FROM audit_logs WHERE category = ? AND action = ? AND target_id = ? ORDER BY id DESC LIMIT 1',
            ['TestArticles', 'update', (string)$articleId]
        )->fetch('assoc');

        $this->assertNotEmpty($log, 'Audit log should exist for update');

        $context = json_decode($log['context'], true);
        $this->assertEquals('Original Title', $context['before']['title']);
        $this->assertEquals('Updated Title', $context['after']['title']);

        // modified should be excluded by default
        $this->assertArrayNotHasKey('modified', $context['before']);
        $this->assertArrayNotHasKey('modified', $context['after']);
    }

    /**
     * エンティティ削除時に、audit_logs に delete ログが記録されることを確認する。
     * before には削除前のデータ、after は空になる。
     *
     * @return void
     */
    public function testDeleteRecordsAuditLog(): void
    {
        // Create first
        $article = $this->articlesTable->newEntity([
            'title' => 'To Delete',
            'body' => 'Will be deleted',
            'author' => 'tester',
            'created' => '2026-07-16 12:00:00',
            'modified' => '2026-07-16 12:00:00',
        ]);
        $saved = $this->articlesTable->save($article);
        $this->assertNotFalse($saved);
        $articleId = $saved->get('id');

        // Now delete
        $article = $this->articlesTable->get($articleId);
        $this->articlesTable->delete($article);

        // Verify audit log was created for delete
        $log = $this->connection->execute(
            'SELECT * FROM audit_logs WHERE category = ? AND action = ? AND target_id = ? ORDER BY id DESC LIMIT 1',
            ['TestArticles', 'delete', (string)$articleId]
        )->fetch('assoc');

        $this->assertNotEmpty($log, 'Audit log should exist for delete');

        $context = json_decode($log['context'], true);
        $this->assertEquals('To Delete', $context['before']['title']);
        $this->assertEmpty($context['after'], 'Delete should have empty after');
    }

    /**
     * テーブルに auditLogSanitize() メソッドを定義した場合、
     * 記録前にサニタイズ（パスワード・メールのマスキング）が適用されることを確認する。
     *
     * @return void
     */
    public function testSanitizeCallbackMasksSensitiveFields(): void
    {
        $article = $this->articlesTable->newEntity([
            'title' => 'Sanitize Test',
            'body' => 'Some body',
            'email' => 'user@example.com',
            'password' => 'secret123',
            'author' => 'tester',
            'created' => '2026-07-16 12:00:00',
            'modified' => '2026-07-16 12:00:00',
        ]);
        $saved = $this->articlesTable->save($article);
        $this->assertNotFalse($saved);

        // Verify audit log was created
        $log = $this->connection->execute(
            'SELECT * FROM audit_logs WHERE category = ? AND action = ? ORDER BY id DESC LIMIT 1',
            ['TestArticles', 'create']
        )->fetch('assoc');

        $this->assertNotEmpty($log);
        $context = json_decode($log['context'], true);

        // password と email がマスキングされていることを確認
        $this->assertEquals('[REDACTED]', $context['after']['password']);
        $this->assertEquals('[REDACTED]', $context['after']['email']);
        // マスキング対象外のカラムは元の値が残る
        $this->assertEquals('Sanitize Test', $context['after']['title']);
    }

    /**
     * excludedFields 設定で指定したカラムが監査ログの context から除外されることを確認する。
     * デフォルトの created/modified に加え author を除外した場合、
     * title は含まれるが author は含まれない。
     *
     * @return void
     */
    public function testCustomExcludedFields(): void
    {
        // Remove existing behavior and re-add with custom config
        $this->articlesTable->behaviors()->unload(AuditLogBehavior::class);
        $this->articlesTable->addBehavior(AuditLogBehavior::class, [
            'connectionName' => 'test',
            'excludedFields' => ['created', 'modified', 'author'],
        ]);

        $article = $this->articlesTable->newEntity([
            'title' => 'Excluded Test',
            'body' => 'Body content',
            'author' => 'should_not_appear',
            'created' => '2026-07-16 12:00:00',
            'modified' => '2026-07-16 12:00:00',
        ]);
        $saved = $this->articlesTable->save($article);
        $this->assertNotFalse($saved);

        $log = $this->connection->execute(
            'SELECT * FROM audit_logs WHERE category = ? AND action = ? ORDER BY id DESC LIMIT 1',
            ['TestArticles', 'create']
        )->fetch('assoc');

        $this->assertNotEmpty($log);

        $context = json_decode($log['context'], true);
        $this->assertArrayNotHasKey('author', $context['after'], 'Author should be excluded');
        $this->assertArrayHasKey('title', $context['after'], 'Title should be included');
    }
}
