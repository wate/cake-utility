<?php

declare(strict_types=1);

namespace CakeUtility\Controller\Component;

use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Router;
use CakeUtility\Audit\AuditLogPurgeService;
use CakeUtility\Audit\AuditLogWriter;

/**
 * AuditLogComponent
 *
 * Controllerから明示的に監査ログを記録するためのComponent。
 * 主にログイン・ログオフ・パスワード変更など、Model層を経由しないイベントで使用する。
 *
 * 使用例:
 * ```
 * $this->AuditLog->saveLog('auth', 'login', context: ['login_id' => $id], purge: true);
 * ```
 *
 * @method \Cake\Datasource\EntityInterface|false saveLog(string $category, string $action, array $context = [], bool $purge = false)
 */
class AuditLogComponent extends Component
{
    /**
     * 監査ログを記録する。
     *
     * メソッド名が `saveLog` なのは基底クラス `Cake\Controller\Component` が
     * すでに PSR-3 準拠の `log(string $message, int $level, array $context): bool`
     * メソッドを持っており、命名が衝突するため。saveLog とすることで Component::log()
     * のシグネチャと干渉せず、IDE の補完も正常に動作する。
     *
     * @param string $category 分類（例：'auth' / 'system' / 'export'）
     * @param string $action アクション種別（例：'login' / 'logout' / 'failed'）
     * @param array<string, mixed> $context 任意の追加情報（ip_address/user_agentは自動取得のため不要）
     * @param bool $purge 記録後にパージを実行するかどうか（ログイン時などに true を渡す）
     * @return \Cake\Datasource\EntityInterface|false 保存結果
     */
    public function saveLog(string $category, string $action, array $context = [], bool $purge = false): \Cake\Datasource\EntityInterface|false
    {
        $request = $this->getController()->getRequest();

        $writer = new AuditLogWriter(new TableLocator());

        $result = $writer->log([
            'category' => $category,
            'action' => $action,
            'context' => $context,
            'ip_address' => $request->clientIp(),
            'user_agent' => $request->getHeaderLine('User-Agent') ?: null,
        ]);

        if ($purge) {
            $this->purge();
        }

        return $result;
    }

    /**
     * 保持期間超過レコードをパージする。
     * AuditLogPurgeService に処理を委譲する。
     *
     * @return void
     */
    private function purge(): void
    {
        $service = new AuditLogPurgeService();
        $service->purge();
    }
}
