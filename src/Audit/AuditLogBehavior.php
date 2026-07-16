<?php

declare(strict_types=1);

namespace CakeUtility\Audit;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\Routing\Router;

/**
 * AuditLogBehavior
 *
 * モデルの作成/更新/削除を検知し、変更履歴を自動記録する。
 * 内部で AuditLogWriter に委譲する。
 *
 * 設定オプション:
 * - `excludedFields`: 記録対象外カラム（デフォルト: ['created', 'modified']）
 * - `connectionName`: 使用するDB接続名（デフォルト: 'default'）
 */
class AuditLogBehavior extends Behavior
{
    /**
     * デフォルト設定
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'excludedFields' => ['created', 'modified'],
        'connectionName' => 'default',
    ];

    /**
     * 新規作成かどうかを追跡（afterSave内でcreate/updateを区別するため）
     *
     * @var bool
     */
    private bool $wasNew = false;

    /**
     * beforeSave イベントハンドラ
     *
     * @param \Cake\Event\EventInterface $event イベント
     * @param \Cake\Datasource\EntityInterface $entity 保存対象エンティティ
     * @param \ArrayObject $options オプション
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $this->wasNew = $entity->isNew();
    }

    /**
     * afterSave イベントハンドラ
     *
     * @param \Cake\Event\EventInterface $event イベント
     * @param \Cake\Datasource\EntityInterface $entity 保存されたエンティティ
     * @param \ArrayObject $options オプション
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $dirtyFields = $entity->getDirty();

        // 除外カラムをフィルタリング
        $excludedFields = $this->getConfig('excludedFields');
        $targetFields = array_diff($dirtyFields, $excludedFields);

        if (empty($targetFields)) {
            return;
        }

        // CakePHP 5: extractOriginal() は存在するフィールドのみを返す
        $before = $entity->extractOriginal($targetFields);

        $after = [];
        foreach ($targetFields as $field) {
            $after[$field] = $entity->get($field);
        }

        if ($this->wasNew) {
            // 新規作成: beforeは空として扱う
            $before = [];
        }

        $action = $this->wasNew ? 'create' : 'update';
        $context = ['before' => $before, 'after' => $after];

        $this->writeAuditLog($entity, $action, $context);
    }

    /**
     * afterDelete イベントハンドラ
     *
     * @param \Cake\Event\EventInterface $event イベント
     * @param \Cake\Datasource\EntityInterface $entity 削除されたエンティティ
     * @param \ArrayObject $options オプション
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        // 削除後でもEntityのメモリ上のデータは残っている
        $before = $entity->toArray();

        // 除外カラムをフィルタリング
        $excludedFields = $this->getConfig('excludedFields');
        foreach ($excludedFields as $field) {
            unset($before[$field]);
        }

        $context = ['before' => $before, 'after' => []];

        $this->writeAuditLog($entity, 'delete', $context);
    }

    /**
     * 監査ログを書き込む（サニタイズコールバック → Writer委譲）
     *
     * @param \Cake\Datasource\EntityInterface $entity 対象エンティティ
     * @param string $action アクション種別（create/update/delete）
     * @param array $context コンテキスト情報（before/after）
     * @return void
     */
    private function writeAuditLog(EntityInterface $entity, string $action, array $context): void
    {
        $table = $this->table();

        // サニタイズコールバック
        if (method_exists($table, 'auditLogSanitize')) {
            $context = $table->auditLogSanitize($action, $context['before'], $context['after']);
        }

        // IP/UAを自動取得
        $ipAddress = null;
        $userAgent = null;
        $request = Router::getRequest();
        if ($request !== null) {
            $ipAddress = $request->clientIp();
            $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        }

        // category はモデル名を自動設定
        $tableAlias = $table->getAlias();

        // target_id は主キー値を文字列化
        $pkField = (array)$table->getPrimaryKey();
        $pkValue = $entity->get($pkField[0]);

        $writer = new AuditLogWriter(new TableLocator(), $this->getConfig('connectionName'));

        $writer->log([
            'category' => $tableAlias,
            'action' => $action,
            'target_id' => $pkValue !== null ? (string)$pkValue : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'context' => $context,
        ]);
    }
}
