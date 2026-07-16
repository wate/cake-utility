<?php

declare(strict_types=1);

namespace CakeUtility\Audit;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;

/**
 * AuditLogWriter
 *
 * 監査ログのデータ保存を担当する中核クラス。
 * 受け取ったデータをそのまま audit_logs テーブルにINSERTする。
 * データ収集(IP/UAの自動取得等)は行わず、呼び出し元(Behavior/Component)の責務とする。
 */
class AuditLogWriter
{
    private TableLocator $tableLocator;

    private string $connectionName;

    /**
     * @param \Cake\ORM\Locator\TableLocator|null $tableLocator テーブルロケーター（省略時はデフォルト）
     * @param string $connectionName 使用するDB接続名（省略時は 'default'）
     */
    public function __construct(?TableLocator $tableLocator = null, string $connectionName = 'default')
    {
        $this->tableLocator = $tableLocator ?? new TableLocator();
        $this->connectionName = $connectionName;
    }

    /**
     * 監査ログを1件登録する。
     *
     * @param array<string, mixed> $data 登録データ
     *   - user_id: ?int
     *   - category: string
     *   - action: string
     *   - target_id: ?string
     *   - ip_address: ?string
     *   - user_agent: ?string
     *   - context: ?array
     * @return \Cake\Datasource\EntityInterface|false 保存結果のエンティティ、失敗時はfalse
     */
    public function log(array $data): EntityInterface|false
    {
        $table = $this->getAuditLogsTable();

        $entity = $table->newEntity([
            'user_id' => $data['user_id'] ?? null,
            'category' => $data['category'] ?? '',
            'action' => $data['action'] ?? '',
            'target_id' => $data['target_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'context' => isset($data['context']) ? json_encode($data['context']) : null,
            'created' => $data['created'] ?? date('Y-m-d H:i:s'),
        ]);

        return $table->save($entity);
    }

    /**
     * 全カテゴリーのデフォルト保持日数を取得する。
     *
     * @return int 保持日数
     */
    public function getRetentionDays(): int
    {
        return (int) Configure::read('AuditLog.retentionDays', 90);
    }

    /**
     * カテゴリーごとの保持日数設定を取得する。
     *
     * @return array<string, mixed> カテゴリーをキーとした保持日数設定
     */
    public function getRetentionByCategory(): array
    {
        return (array) Configure::read('AuditLog.retentionByCategory', []);
    }

    /**
     * 指定されたカテゴリーとアクションの保持日数を取得する。
     * カテゴリー/アクションごとの個別設定があればそれを、なければデフォルト値を返す。
     *
     * @param string $category カテゴリー
     * @param string $action アクション
     * @return int 保持日数
     */
    public function getRetentionFor(string $category, string $action): int
    {
        $retentionByCategory = $this->getRetentionByCategory();

        if (isset($retentionByCategory[$category][$action])) {
            return (int) $retentionByCategory[$category][$action];
        }

        return $this->getRetentionDays();
    }

    /**
     * audit_logs テーブルのインスタンスを取得する。
     *
     * @return \Cake\ORM\Table
     */
    private function getAuditLogsTable(): Table
    {
        /** @var \Cake\ORM\Table $table */
        $table = $this->tableLocator->get('AuditLogs', [
            'connectionName' => $this->connectionName,
        ]);

        return $table;
    }
}
