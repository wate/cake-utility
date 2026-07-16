<?php

declare(strict_types=1);

namespace CakeUtility\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use CakeUtility\Audit\AuditLogPurgeService;

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
            'help' => '強制実行（確認プロンプトをスキップする）',
        ])->addOption('connection', [
            'short' => 'c',
            'help' => '使用するDB接続名（デフォルト: default）',
            'default' => 'default',
        ]);

        $parser->setDescription('保持期間超過の監査ログをCSV出力後、DBから削除する');

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

        $io->out(sprintf(
            '<success>パージ完了: %d 件出力 / %d 件削除</success>',
            $result['exported'],
            $result['purged']
        ));

        return static::CODE_SUCCESS;
    }
}
