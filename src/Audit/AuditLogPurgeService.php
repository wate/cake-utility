<?php

declare(strict_types=1);

namespace CakeUtility\Audit;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;

/**
 * AuditLogPurgeService
 *
 * 保持期間超過レコードのパージ処理（CSV出力→DB削除→古いCSV削除）を担当する。
 * ComponentとCommandの両方から共通して利用されるサービス。
 */
class AuditLogPurgeService
{
    /**
     * 使用するDB接続名
     *
     * @var string
     */
    private string $connectionName;

    /**
     * audit_logs テーブルのインスタンス
     *
     * @var \Cake\ORM\Table
     */
    private Table $auditLogsTable;

    /**
     * コンストラクタ
     *
     * @param string $connectionName 使用するDB接続名（省略時は 'default'）
     */
    public function __construct(string $connectionName = 'default')
    {
        $this->connectionName = $connectionName;
        $this->auditLogsTable = (new TableLocator())->get('AuditLogs', [
            'connectionName' => $connectionName,
        ]);
    }

    /**
     * パージを実行する。
     * 保持期間超過レコードをCSV出力し、DBから削除する。
     * その後、保持期間を超過した古いCSVファイルも削除する。
     *
     * @return array<string, int> 処理結果['exported' => 出力行数, 'purged' => 削除件数]
     */
    public function purge(): array
    {
        $purgedTotal = 0;
        $exportedTotal = 0;

        $categories = $this->getExpiredGroups();

        foreach ($categories as $category => $actions) {
            foreach ($actions as $action => $retentionDays) {
                $records = $this->findExpiredRecords($category, $action, $retentionDays);

                if (empty($records)) {
                    continue;
                }

                // CSV出力
                $exported = $this->exportToCsv($category, $records);
                $exportedTotal += $exported;

                // DB削除
                $ids = array_column($records, 'id');
                $this->deleteRecords($ids);
                $purgedTotal += count($ids);
            }
        }

        // 古いCSVファイルの削除
        $this->cleanupOldCsvFiles();

        return ['exported' => $exportedTotal, 'purged' => $purgedTotal];
    }

    /**
     * 保持期間超過レコードを持つカテゴリー＋アクションの一覧を取得する。
     * 保持期間は各カテゴリー/アクションごとに findExpiredRecords() で判定するため、
     * ここでは全グループを取得する。
     *
     * @return array<string, array<string, int>> [category => [action => retentionDays, ...], ...]
     */
    private function getExpiredGroups(): array
    {
        $connection = ConnectionManager::get($this->connectionName);
        if (!($connection instanceof Connection)) {
            return [];
        }

        $defaultRetention = (int) Configure::read('AuditLog.retentionDays', 90);
        $retentionByCategory = (array) Configure::read('AuditLog.retentionByCategory', []);

        // 全てのカテゴリー+アクションの組み合わせを取得
        $rows = $connection->execute(
            'SELECT DISTINCT category, action FROM audit_logs'
        )->fetchAll('assoc');

        $groups = [];
        foreach ($rows as $row) {
            $category = $row['category'];
            $action = $row['action'];

            // カテゴリー/アクションごとの個別保持期間を確認
            $retention = $retentionByCategory[$category][$action] ?? $defaultRetention;

            $groups[$category][$action] = $retention;
        }

        return $groups;
    }

    /**
     * 指定されたカテゴリー・アクションの保持期間超過レコードを取得する。
     *
     * @param string $category カテゴリー
     * @param string $action アクション
     * @param int $retentionDays 保持日数
     * @return array<int, array<string, mixed>> レコード一覧
     */
    private function findExpiredRecords(string $category, string $action, int $retentionDays): array
    {
        $connection = ConnectionManager::get($this->connectionName);
        if (!($connection instanceof Connection)) {
            return [];
        }

        // ドライバによって日付計算SQLを切り替え
        $dateCondition = $this->dateSubSql($connection, $retentionDays);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $connection->execute(
            "SELECT * FROM audit_logs WHERE category = ? AND action = ? AND created < {$dateCondition}",
            [$category, $action]
        )->fetchAll('assoc');

        return $rows;
    }

    /**
     * データベースドライバに応じた日付計算SQLを返す。
     *
     * @param \Cake\Database\Connection $connection 接続
     * @param int $days 差分数
     * @return string SQL断片
     */
    private function dateSubSql(Connection $connection, int $days): string
    {
        $driver = $connection->getDriver();

        if ($driver instanceof Sqlite) {
            return sprintf("datetime('now', '-%d days')", $days);
        }

        // MySQL / MariaDB
        return sprintf('DATE_SUB(NOW(), INTERVAL %d DAY)', $days);
    }

    /**
     * CSV出力先パスを取得する。
     *
     * @return string
     */
    private function getCsvExportPath(): string
    {
        $path = (string) Configure::read('AuditLog.csvExportPath', '');
        if ($path !== '') {
            return rtrim($path, '/\\') . '/';
        }

        // LOGS 定数が未定義の場合のフォールバック
        $logsDir = defined('LOGS') ? LOGS : (dirname(__DIR__, 3) . '/logs/');

        return rtrim($logsDir, '/\\') . '/audit/';
    }

    /**
     * レコードをCSVファイルに出力する。
     *
     * @param string $category カテゴリー
     * @param array<int, array<string, mixed>> $records 出力するレコード
     * @return int 出力行数
     */
    private function exportToCsv(string $category, array $records): int
    {
        $exportDir = $this->getCsvExportPath();

        if (!is_dir($exportDir)) {
            // @codeCoverageIgnoreStart
            mkdir($exportDir, 0755, true);
            // @codeCoverageIgnoreEnd
        }

        $date = date('Y-m-d');
        $filename = sprintf('audit_logs_%s_%s.csv', $category, $date);
        $filepath = $exportDir . $filename;

        // 追記モード（既存ファイルがなければヘッダー行を追加）
        $fileExists = file_exists($filepath);
        $handle = fopen($filepath, 'a');
        if ($handle === false) {
            return 0;
        }

        if (!$fileExists && !empty($records)) {
            // ヘッダー行
            fputcsv($handle, array_keys($records[0]), ',', '"', '\\', '');
        }

        $count = 0;
        foreach ($records as $row) {
            fputcsv($handle, $row, ',', '"', '\\', '');
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * レコードをDBから物理削除する。
     *
     * @param array<int|string> $ids 削除するID一覧
     * @return void
     */
    private function deleteRecords(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $connection = ConnectionManager::get($this->connectionName);
        if (!($connection instanceof Connection)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $connection->execute(
            "DELETE FROM audit_logs WHERE id IN ({$placeholders})",
            $ids
        );
    }

    /**
     * 保持期間を超過した古いCSVファイルを削除する。
     *
     * @return void
     */
    private function cleanupOldCsvFiles(): void
    {
        $csvExportPath = $this->getCsvExportPath();
        $defaultRetention = (int) Configure::read('AuditLog.csvRetentionDays', 365);
        $retentionByCategory = (array) Configure::read('AuditLog.csvRetentionByCategory', []);

        if (!is_dir($csvExportPath)) {
            return;
        }

        $files = glob($csvExportPath . 'audit_logs_*.csv');
        if ($files === false) {
            return;
        }

        foreach ($files as $filepath) {
            $filename = basename($filepath);

            // ファイル名からカテゴリーと日付を抽出: audit_logs_{category}_{YYYY-MM-DD}.csv
            if (!preg_match('/^audit_logs_(.+)_(\d{4}-\d{2}-\d{2})\.csv$/', $filename, $matches)) {
                continue;
            }

            $category = $matches[1];
            $fileDate = $matches[2];

            $retentionDays = $retentionByCategory[$category]['__default__'] ?? $defaultRetention;

            $thresholdDate = date('Y-m-d', strtotime("-{$retentionDays} days"));

            if ($fileDate < $thresholdDate) {
                @unlink($filepath);
            }
        }
    }
}
