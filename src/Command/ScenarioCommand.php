<?php

declare(strict_types=1);

namespace CakeUtility\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Utility\Inflector;
use CakeUtility\Yaml\ScenarioLoader;

/**
 * Scenario コマンド
 *
 * YAMLシナリオファイルからテストデータをデータベースに投入・削除するためのCLIコマンド。
 *
 * 使用例:
 * ```bash
 * bin/cake scenario load hearings                           # hearingsシナリオ読み込み
 * bin/cake scenario load hearings users                     # hearingsからusersテーブルのみ
 * bin/cake scenario load                                    # 全YAMLファイル読み込み
 * bin/cake scenario clear hearings                          # hearingsシナリオ削除
 * ```
 */
class ScenarioCommand extends Command
{
    /**
     * ベースディレクトリのデフォルト値
     */
    public const DEFAULT_BASE_DIR = 'config/Seeds/data';

    /**
     * コマンドの名前
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'scenario';
    }

    /**
     * コマンドの説明
     *
     * @return string
     */
    public function description(): string
    {
        return 'Load or clear test scenario seed data from YAML files.';
    }

    /**
     * コマンドのヘルプ
     *
     * @return string
     */
    public function help(): string
    {
        return <<<'TEXT'
Load or clear test scenario seed data from YAML files.

Usage:
  bin/cake scenario <action> [<scenario>] [<table>] [--base-dir=DIR]

Arguments:
  action          'load' or 'clear'.
  scenario        Scenario name (file or directory name). Omit to load/clear all scenarios.
  table           Target table name. Omit to process all tables defined in scenario.

Options:
  --base-dir      Base directory containing YAML scenario files.
                  Defaults to: config/Seeds/data

Examples:
  bin/cake scenario load                        # Load all YAML files in base directory
  bin/cake scenario load hearings               # Load hearings scenario
  bin/cake scenario load hearings users         # Load users table from hearings
  bin/cake scenario clear hearings              # Clear hearings scenario
  bin/cake scenario load --base-dir=DIR         # Specify base directory
TEXT;
    }

    /**
     * コマンド引数を定義
     *
     * @param ConsoleOptionParser $parser パーサーオブジェクト
     * @return ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('action', [
                'help' => 'Action: load or clear.',
                'required' => true,
            ])
            ->addArgument('scenario', [
                'help' => 'Scenario name (file or directory name). Omit to load/clear all.',
                'required' => false,
            ])
            ->addArgument('table', [
                'help' => 'Target table name. Omit to process all tables.',
                'required' => false,
            ])
            ->addOption('base-dir', [
                'help' => 'Base directory containing YAML scenario files',
                'default' => self::DEFAULT_BASE_DIR,
            ]);

        return $parser;
    }

    /**
     * コマンド実行
     *
     * @param Arguments $args 引数オブジェクト
     * @param ConsoleIo $io  I/Oオブジェクト
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $action = $args->getOption('action') ?: $args->getArgument('action');
        $scenario = $args->getOption('scenario') ?: $args->getArgument('scenario');
        $table = $args->getOption('table') ?: $args->getArgument('table');
        $baseDir = $args->getOption('base-dir');

        // Normalize table name: 'Plugin.TableName' -> 'table_name'
        // This handles both explicit plugin-qualified names and inferred table names
        if ($table !== null && strpos($table, '.') !== false) {
            [$plugin, $tableName] = explode('.', $table, 2);
            $table = Inflector::tableize($tableName);
        }

        // base-dirを絶対パスに変換
        $baseDirPath = $this->resolveBaseDir($baseDir);

        if (!is_dir($baseDirPath)) {
            $io->error("Base directory not found: {$baseDirPath}");
            return self::CODE_ERROR;
        }

        // シナリオファイルの取得
        $scenarioFiles = $this->getScenarioFiles($baseDirPath, $scenario);

        if (empty($scenarioFiles)) {
            $io->warning('No scenario files found.');
            return self::CODE_ERROR;
        }

        $io->out('==================================================');
        $io->out("Scenario: {$action}");
        $io->out("Base directory: {$baseDirPath}");
        if ($scenario) {
            $io->out("Scenario: {$scenario}");
        }
        if ($table) {
            $io->out("Table: {$table}");
        }
        $io->out('--------------------------------------------------');

        if ($action === 'clear') {
            return $this->executeClear($scenarioFiles, $table, $baseDirPath, $io);
        }

        return $this->executeLoad($scenarioFiles, $table, $baseDirPath, $io);
    }

    /**
     * base-dirを絶対パスに変換
     *
     * @param string $baseDir ベースディレクトリ
     * @return string
     */
    protected function resolveBaseDir(string $baseDir): string
    {
        // 相対パスの場合はカレントディレクトリを基準にする
        if (!str_starts_with($baseDir, DS) && !str_starts_with($baseDir, '/')) {
            $baseDir = getcwd() . DS . $baseDir;
        }

        return rtrim($baseDir, DS);
    }

    /**
     * シナリオ名からYAMLファイルを取得（ディレクトリのみ対象）
     *
     * @param string $baseDir ベースディレクトリ
     * @param string|null $scenario シナリオ名
     * @return array<string> ファイルパスの配列
     */
    protected function getScenarioFiles(string $baseDir, ?string $scenario): array
    {
        if ($scenario) {
            // 指定されたシナリオディレクトリを検索
            $scenarioPath = $baseDir . DS . $scenario;

            if (!is_dir($scenarioPath)) {
                return [];
            }

            return $this->collectYamlFiles($scenarioPath);
        }

        // シナリオ名省略時: base-dir直下の全YAMLファイル
        return $this->collectYamlFiles($baseDir);
    }

    /**
     * ディレクトリ内のYAMLファイルを直下から取得（再帰検索なし）
     *
     * @param string $dir ディレクトリパス
     * @return array<string> ファイルパスの配列
     */
    protected function collectYamlFiles(string $dir): array
    {
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'yml' || $file->getExtension() === 'yaml') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * シナリオ一括読み込み
     *
     * @param array<string> $files ファイルパスの配列
     * @param string|null $table テーブル名（省略時は全テーブル）
     * @param string $baseDirPath ベースディレクトリパス
     * @param ConsoleIo $io I/Oオブジェクト
     * @return int
     */
    protected function executeLoad(array $files, ?string $table, string $baseDirPath, ConsoleIo $io): int
    {
        $loader = new ScenarioLoader($baseDirPath);
        $totalInserted = 0;
        $totalUpdated = 0;
        $error = false;

        foreach ($files as $file) {
            $relativePath = str_replace(getcwd() . DS, '', $file);
            $io->out("Processing: {$relativePath}");

            try {
                $scenarioName = $this->extractScenarioName($file, $baseDirPath);
                $result = $loader->load($scenarioName, $table);

                $inserted = $result['records_inserted'] ?? 0;
                $updated = $result['records_updated'] ?? 0;
                $totalInserted += $inserted;
                $totalUpdated += $updated;

                $io->out("  Inserted: {$inserted}, Updated: {$updated}");
            } catch (\RuntimeException $e) {
                $io->error("  Error: {$e->getMessage()}");
                $error = true;
            }
        }

        $io->out('--------------------------------------------------');
        $io->out("Total - Inserted: {$totalInserted}, Updated: {$totalUpdated}");

        if ($error) {
            return self::CODE_ERROR;
        }

        return self::CODE_SUCCESS;
    }

    /**
     * シナリオ一括削除
     *
     * @param array<string> $files ファイルパスの配列
     * @param string|null $table テーブル名（省略時は全テーブル）
     * @param string $baseDirPath ベースディレクトリパス
     * @param ConsoleIo $io I/Oオブジェクト
     * @return int
     */
    protected function executeClear(array $files, ?string $table, string $baseDirPath, ConsoleIo $io): int
    {
        if (empty($files)) {
            $io->warning('No YAML files found.');
            return self::CODE_SUCCESS;
        }
        $loader = new ScenarioLoader($baseDirPath);
        $totalDeleted = 0;
        $error = false;

        foreach ($files as $file) {
            $relativePath = str_replace(getcwd() . DS, '', $file);
            $io->out("Processing: {$relativePath}");

            try {
                $scenarioName = $this->extractScenarioName($file, $baseDirPath);
                $deleted = $loader->clear($scenarioName, $table);
                $totalDeleted += $deleted;

                $io->out("  Deleted: {$deleted}");
            } catch (\RuntimeException $e) {
                $io->error("  Error: {$e->getMessage()}");
                $error = true;
            }
        }

        $io->out('--------------------------------------------------');
        $io->out("Total - Deleted: {$totalDeleted}");

        if ($error) {
            return self::CODE_ERROR;
        }

        return self::CODE_SUCCESS;
    }

    /**
     * ファイルパスからシナリオ名を抽出
     *
     * @param string $filePath ファイルパス（絶対パス）
     * @param string $baseDir ベースディレクトリ（絶対パス）
     * @return string シナリオ名（拡張子なし）
     */
    protected function extractScenarioName(string $filePath, string $baseDir): string
    {
        // ベースディレクトリからの相対パスを取得
        $relativePath = substr($filePath, strlen($baseDir) + 1);

        // ディレクトリ区切り文字で分割
        $parts = explode(DS, $relativePath);

        // 最後のパートから拡張子を除去
        $lastPart = array_pop($parts);
        $scenarioName = basename($lastPart, '.' . pathinfo($lastPart, PATHINFO_EXTENSION));

        // ディレクトリが複数ある場合は、最初のディレクトリ（シナリオ名）を返す
        if (!empty($parts)) {
            return $parts[0];
        }

        return $scenarioName;
    }
}
