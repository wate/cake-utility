<?php

declare(strict_types=1);

namespace CakeUtility\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use CakeUtility\Audit\AuditLogPurgeService;

use function Cake\I18n\__d;

/**
 * AuditLogPurgeCommand
 *
 * 保持期間超過レコードをCSV出力後、DBから削除するCakePHP Command。
 * 手動またはcronでの一括パージに使用する。
 *
 * 使用例:
 * ```bash
 * bin/cake audit_log_purge              # 通常パージ
 * bin/cake audit_log_purge --force      # 強制実行
 * bin/cake audit_log_purge --connection test   # test接続で実行
 * ```
 */
class AuditLogPurgeCommand extends Command
{
    /**
     * オプションの設定
     *
     * @param \Cake\Console\ConsoleOptionParser $parser パーサー
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser->addOption('force', [
            'short' => 'f',
            'boolean' => true,
            'help' => __d('cake_utility', 'Force execution (skip confirmation prompt)'),
        ])->addOption('connection', [
            'short' => 'c',
            'help' => __d('cake_utility', 'Database connection name (default: default)'),
            'default' => 'default',
        ]);

        $parser->setDescription(__d('cake_utility', 'Export expired audit logs to CSV and delete from database'));

        return $parser;
    }

    /**
     * コマンド実行
     *
     * @param \Cake\Console\Arguments $args 引数
     * @param \Cake\Console\ConsoleIo $io 入出力
     * @return int 終了コード
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connectionName = $args->getOption('connection') ?? 'default';

        $service = new AuditLogPurgeService($connectionName);
        $result = $service->purge();

        $io->out(
            '<success>' . __d('cake_utility', 'Purge completed: {0} exported, {1} purged', $result['exported'], $result['purged']) . '</success>'
        );

        return static::CODE_SUCCESS;
    }
}
