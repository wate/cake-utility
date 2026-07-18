<?php

declare(strict_types=1);

namespace CakeUtility\Yaml;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use RuntimeException;

use function Cake\I18n\__d;

/**
 * ScenarioLoader
 *
 * YAMLシナリオデータをデータベースに投入・削除するオーケストレーター。
 * Yaml\Loader からパース済みYAMLデータを受け取り、参照解決(refMap)、
 * 冪等なupsert、トランザクション制御を含むDB永続化フローを管理する。
 */
class ScenarioLoader
{
    /**
     * シナリオファイルのベースディレクトリパス
     *
     * @var string
     */
    protected string $basePath;

    /**
     * 参照マップ
     *
     * _ref ラベルと実際のデータベースIDを対応付ける。
     *
     * @var array<string, mixed>
     */
    protected array $refMap = [];

    /**
     * テーブルロケーター
     *
     * @var \Cake\ORM\Locator\TableLocator
     */
    protected TableLocator $tableLocator;

    /**
     * テーブル解決に使用するDB接続名
     *
     * @var string
     */
    protected string $connectionName = 'default';

    /**
     * コンストラクタ
     *
     * @param string|null $basePath シナリオファイルのベースディレクトリパス
     *   null の場合は `Configure::read('Scenario.baseDir')` から読み込む。
     *   それも未設定の場合は '{CACHE}scenarios' を使用する。
     * @param \Cake\ORM\Locator\TableLocator|null $tableLocator テーブルロケーター（省略時はデフォルト）
     * @param string $connectionName テーブル解決に使用するDB接続名
     */
    public function __construct(?string $basePath = null, ?TableLocator $tableLocator = null, string $connectionName = 'default')
    {
        $resolvedPath = $basePath
            ?? Configure::read('Scenario.baseDir')
            ?? CACHE . 'scenarios';
        $this->basePath = rtrim($resolvedPath, DS);
        $this->tableLocator = $tableLocator ?? new TableLocator();
        $this->connectionName = $connectionName;
    }

    /**
     * 指定されたシナリオ名のデータをデータベースに投入する。
     *
     * @param string $scenarioName シナリオ名（YAMLファイルを含むディレクトリ名）
     * @param string|array<string>|null $tableNames 対象テーブル名（省略時は全テーブル）
     * @return array<string, int> 'records_inserted' と 'records_updated' を含む連想配列
     * @throws \RuntimeException ロード失敗時
     */
    public function load(string $scenarioName, string|array|null $tableNames = null): array
    {
        $scenarioPath = $this->resolvePath($scenarioName);
        $yamlFiles = $this->collectYamlFilesFromScenario($scenarioPath);

        // Phase 1: Build dependency graph and sort files based on data dependencies
        $yamlFiles = $this->orderFilesByDependency($yamlFiles);

        // Normalize $tableNames to array
        $tableFilter = $tableNames ? (array)$tableNames : null;

        $totalInserted = 0;
        $totalUpdated = 0;

        foreach ($yamlFiles as $filePath) {
            // Infer table name from file name
            $inferredTableName = $this->resolveTableNameFromFile($filePath);

            // Filter by specified table names if provided
            if ($tableFilter !== null && !in_array($inferredTableName, $tableFilter, true)) {
                continue;
            }

            $result = $this->loadTable($filePath, $inferredTableName);
            $totalInserted += $result['records_inserted'];
            $totalUpdated += $result['records_updated'];
        }

        return [
            'records_inserted' => $totalInserted,
            'records_updated' => $totalUpdated,
        ];
    }

    /**
     * 単一テーブルをYAMLファイルからロードする。
     *
     * @param string $filePath YAMLファイルの絶対パス
     * @param string $tableName 対象のCakePHPテーブルエイリアス
     * @return array<string, int> 'records_inserted' と 'records_updated' を含む連想配列
     * @throws \RuntimeException ロード失敗時
     */
    protected function loadTable(string $filePath, string $tableName): array
    {
        $table = $this->getTable($tableName);
        $records = $this->parseYaml($filePath);

        $loader = new Loader();
        // Current refMap is passed to the loader for reference resolution
        $resolvedRecords = $loader->resolve($records, $this->refMap, $table);

        $inserted = 0;
        $updated = 0;

        $table->getConnection()->transactional(function () use ($table, $resolvedRecords, $filePath, &$inserted, &$updated) {
            foreach ($resolvedRecords as $index => $record) {
                $this->persistRecord($table, $record, $filePath, $index, $inserted, $updated);
            }
        });

        return [
            'records_inserted' => $inserted,
            'records_updated' => $updated,
        ];
    }

    /**
     * 指定されたシナリオのデータをデータベースから削除する。
     *
     * 依存関係を考慮し、逆順でレコードを削除する。
     *
     * @param string $scenarioName シナリオ名（YAMLファイルを含むディレクトリ名）
     * @param string|array<string>|null $tableNames 対象テーブル名（省略時は全テーブル）
     * @return int 削除したレコード数
     * @throws \RuntimeException 削除失敗時
     */
    public function clear(string $scenarioName, string|array|null $tableNames = null): int
    {
        $scenarioPath = $this->resolvePath($scenarioName);
        $yamlFiles = $this->collectYamlFilesFromScenario($scenarioPath);

        // Reverse order for deletion (dependents first)
        $yamlFiles = array_reverse($yamlFiles);

        // Normalize $tableNames to array
        $tableFilter = $tableNames ? (array)$tableNames : null;

        $totalDeleted = 0;

        foreach ($yamlFiles as $filePath) {
            // Infer table name from file name
            $inferredTableName = $this->resolveTableNameFromFile($filePath);

            // Filter by specified table names if provided
            if ($tableFilter !== null && !in_array($inferredTableName, $tableFilter, true)) {
                continue;
            }

            $deleted = $this->clearTable($filePath, $inferredTableName);
            $totalDeleted += $deleted;
        }

        return $totalDeleted;
    }

    /**
     * 単一テーブルをYAMLファイルからクリアする。
     *
     * @param string $filePath YAMLファイルの絶対パス
     * @param string $tableName 対象のCakePHPテーブルエイリアス
     * @return int 削除したレコード数
     * @throws \RuntimeException 削除失敗時
     */
    protected function clearTable(string $filePath, string $tableName): int
    {
        $table = $this->getTable($tableName);
        $records = $this->parseYaml($filePath);

        // Extract keys and refs from original YAML records (in reverse order)
        $loader = new Loader();
        $parsedRecords = $loader->parse(file_get_contents($filePath));

        $deleted = 0;

        $table->getConnection()->transactional(function () use ($table, $parsedRecords, $filePath, &$deleted) {
            // Delete in reverse order (dependents first)
            for ($i = count($parsedRecords) - 1; $i >= 0; $i--) {
                $record = $parsedRecords[$i];
                $keys = $record['_keys'] ?? null;
                $ref = $record['_ref'] ?? null;

                if ($keys === null) {
                    continue;
                }

                // Find entity by keys
                $entity = $this->findOrCreateEntity($table, $record, $keys);
                if ($entity->isNew()) {
                    continue; // Nothing to delete
                }

                try {
                    $table->deleteOrFail($entity);
                    $deleted++;
                } catch (\InvalidArgumentException $e) {
                    throw new RuntimeException(
                        __d('cake_utility', 'Failed to delete record at index {0} in {1}: {2}', $i, $filePath, $e->getMessage())
                    );
                }

                // Update refMap to indicate deleted
                if ($ref !== null) {
                    $this->refMap[$ref] = null; // null = deleted
                }
            }
        });

        return $deleted;
    }

    /**
     * 内部参照マップをリセットする。
     *
     * @return void
     */
    public function resetRefMap(): void
    {
        $this->refMap = [];
    }

    /**
     * テーブルインスタンスを取得する。
     *
     * @param string $tableName CakePHPテーブルエイリアス
     * @return \Cake\ORM\Table
     */
    protected function getTable(string $tableName): Table
    {
        return $this->tableLocator->get($tableName, ['connectionName' => $this->connectionName]);
    }

    /**
    /**
     * 単一レコードをupsertロジックでデータベースに永続化する。
     *
     * @param \Cake\ORM\Table $table 対象テーブル
     * @param array<string, mixed> $record 解決済みレコードデータ
     * @param string $filePath エラー報告用の元ファイルパス
     * @param int $index エラー報告用のレコードインデックス
     * @param int &$inserted 挿入件数の参照カウンター
     * @param int &$updated 更新件数の参照カウンター
     * @return void
     * @throws \RuntimeException 永続化失敗時
     */
    protected function persistRecord(Table $table, array $record, string $filePath, int $index, int &$inserted, int &$updated): void
    {
        // Note: $record is already resolved by Loader::resolve in load()

        // Get the _ref label from the original YAML to update the refMap
        $originalRecords = $this->parseYaml($filePath);
        $originalRecord = $originalRecords[$index] ?? [];
        $ref = $originalRecord['_ref'] ?? null;

        // Get the lookup keys from the original YAML
        $keys = $originalRecord['_keys'] ?? null;

        // Serialize JSON-type columns before setting on Entity
        $record = $this->serializeJsonColumns($table, $record);

        // ID確定: id指定あり(Fixture的)または_keys-based(Seed的)
        $entity = $this->resolveEntity($table, $record, $originalRecord, $keys);
        $entity->patch($record);

        // Note: saveOrFail() sets isNew(false) after successful save, so capture it first
        $wasNew = $entity->isNew();

        try {
            $saved = $table->saveOrFail($entity);
        } catch (PersistenceFailedException $e) {
            throw new RuntimeException(
                __d('cake_utility', 'Failed to save record at index {0} in {1}: {2}', (string)$index, $filePath, $e->getMessage())
            );
        }

        if ($wasNew) {
            $inserted++;
        } else {
            $updated++;
        }

        if ($ref !== null) {
            $pk = $table->getPrimaryKey();
            if (is_array($pk)) {
                $id = [];
                foreach ($pk as $key) {
                    $id[$key] = $saved->get($key);
                }
            } else {
                $id = $saved->get($pk);
            }
            $this->refMap[$ref] = $id;
        }
    }

    /**
     * ID戦略に基づいて永続化用のエンティティを解決する。
     *
     * - Fixture方式: YAMLに `id` が明示指定されている場合、主キーで既存検索→新規作成
     * - Seed方式: `id` がない場合、`_keys` ベースで既存検索→空エンティティ作成
     *
     * @param \Cake\ORM\Table $table 対象テーブル
     * @param array<string, mixed> $record 参照解決済みのレコードデータ
     * @param array<string, mixed> $originalRecord 解決前の元YAMLレコード
     * @param string|array<string>|null $keys Seed方式の検索キー
     * @return \Cake\ORM\Entity
     */
    protected function resolveEntity(Table $table, array $record, array $originalRecord, string|array|null $keys): EntityInterface
    {
        $pk = $table->getPrimaryKey();
        $hasId = isset($record['id']) && $record['id'] !== null;

        // Case 1: id is explicitly specified (Fixture-style)
        if ($hasId) {
            $id = $record['id'];

            // Build conditions using primary key(s)
            $conditions = [];
            if (is_string($pk) && $pk === 'id') {
                $conditions[$pk] = $id;
            } elseif (is_array($pk)) {
                // Composite primary key: id must be associative array
                if (is_array($id)) {
                    foreach ($pk as $key) {
                        $conditions[$key] = $id[$key] ?? null;
                    }
                }
            }

            if (!empty($conditions)) {
                $entity = $table->find()->where($conditions)->first();
                if ($entity) {
                    return $entity;
                }
            }

            // Entity not found - create new with specified ID
            $entity = $table->newEmptyEntity();
            if (is_string($pk)) {
                $entity->set($pk, $id);
            } elseif (is_array($pk) && is_array($id)) {
                foreach ($pk as $key) {
                    if (isset($id[$key])) {
                        $entity->set($key, $id[$key]);
                    }
                }
            }

            return $entity;
        }

        // Case 2: id is not specified (Seed-style) - use _keys-based lookup
        return $this->findOrCreateEntity($table, $record, $keys);
    }

    /**
     * キー条件で既存エンティティを検索し、なければ新規作成する。
     *
     * @param \Cake\ORM\Table $table 対象テーブル
     * @param array<string, mixed> $data 解決済みデータ
     * @param string|array<string>|null $keys 検索キーフィールド
     * @return \Cake\ORM\Entity
     */
    protected function findOrCreateEntity(Table $table, array $data, string|array|null $keys)
    {
        if ($keys === null) {
            return $table->newEmptyEntity();
        }

        $keys = is_string($keys) ? [$keys] : $keys;
        $conditions = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $conditions[$key] = $data[$key];
            }
        }

        if (empty($conditions)) {
            return $table->newEmptyEntity();
        }

        $entity = $table->find()->where($conditions)->first();

        return $entity ?? $table->newEmptyEntity();
    }

    /**
     * 永続化前にJSON型カラムをシリアライズする。
     *
     * ORMのJsonTypeは保存時に自動エンコードされるため、json型のカラムは
     * 手動エンコード不要。text型のカラムのみ手動でJSONエンコードする。
     *
     * @param \Cake\ORM\Table $table 対象テーブル
     * @param array<string, mixed> $record レコードデータ
     * @return array<string, mixed>
     */
    protected function serializeJsonColumns(Table $table, array $record): array
    {
        $schema = $table->getSchema();
        foreach ($record as $column => $value) {
            if (is_array($value)) {
                $columnType = $schema->getColumnType($column);
                if ($columnType === 'text') {
                    $record[$column] = json_encode($value, JSON_THROW_ON_ERROR);
                }
            }
        }
        return $record;
    }

    /**
     * シナリオディレクトリから全YAMLファイルを収集する。
     *
     * @param string $scenarioPath シナリオディレクトリのパス
     * @return array<string> ソート済みのYAMLファイルパス配列
     */
    protected function collectYamlFilesFromScenario(string $scenarioPath): array
    {
        $files = [];

        if (is_file($scenarioPath)) {
            // 単一ファイル
            return [$scenarioPath];
        }

        if (!is_dir($scenarioPath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scenarioPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'yml' || $file->getExtension() === 'yaml') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /**
     * YAMLファイルをデータ依存関係に基づいて並べ替える。
     *
     * 各YAMLファイルが参照する ref: ラベルから依存グラフを構築し、
     * トポロジカルソート順でファイルを返す。
     *
     * @param array<string> $yamlFiles YAMLファイルパスのリスト
     * @return array<string> 依存順に並べ替えられたファイルパス（依存先優先）
     * @throws \RuntimeException 循環依存を検出した場合
     */
    protected function orderFilesByDependency(array $yamlFiles): array
    {
        if (count($yamlFiles) <= 1) {
            return $yamlFiles;
        }

        $graph = $this->buildDependencyGraph($yamlFiles);
        return $this->topologicalSort($yamlFiles, $graph);
    }

    /**
     * 依存グラフを構築する。
     *
     * 各ファイルから ref: 参照を抽出し、どのファイルがどの _ref ラベルを
     * 定義しているかに基づいて依存関係を特定する。
     *
     * @param array<string> $yamlFiles YAMLファイルパスのリスト
     * @return array<string, array<string>> 依存グラフ: filePath => [依存ファイルパス]
     */
    protected function buildDependencyGraph(array $yamlFiles): array
    {
        $graph = [];
        $refToFile = []; // Maps each _ref label to the file that defines it

        // Phase 1: Scan all files to build _ref => file mapping
        $loader = new Loader();
        foreach ($yamlFiles as $filePath) {
            $parsed = $loader->parse(file_get_contents($filePath));
            if (!is_array($parsed)) {
                continue;
            }

            // Handle both single record (associative array) and multiple records (array of arrays)
            $records = isset($parsed[0]) && is_array($parsed[0]) ? $parsed : [$parsed];

            foreach ($records as $record) {
                if (is_array($record) && isset($record['_ref'])) {
                    $ref = $record['_ref'];
                    $refToFile[$ref] = $filePath;
                }
            }
        }

        // Phase 2: For each file, extract ref: references and build dependency list
        foreach ($yamlFiles as $filePath) {
            $graph[$filePath] = [];
            $references = $loader->extractReferences($filePath);

            foreach ($references as $ref) {
                // If this reference is defined in another file, that file is a dependency
                if (isset($refToFile[$ref]) && $refToFile[$ref] !== $filePath) {
                    $dependencyFile = $refToFile[$ref];
                    if (!in_array($dependencyFile, $graph[$filePath], true)) {
                        $graph[$filePath][] = $dependencyFile;
                    }
                }
            }
        }

        return $graph;
    }

    /**
     * 依存グラフをトポロジカルソートする（Kahnのアルゴリズム）。
     *
     * @param array<string> $yamlFiles YAMLファイルパスのリスト
     * @param array<string, array<string>> $graph 依存グラフ
     * @return array<string> ソート済みファイルリスト（依存先優先）
     * @throws \RuntimeException 循環依存を検出した場合
     */
    protected function topologicalSort(array $yamlFiles, array $graph): array
    {
        // Build in-degree count for each file
        $inDegree = array_combine($yamlFiles, array_fill(0, count($yamlFiles), 0));
        $adjacencyList = array_combine($yamlFiles, array_fill(0, count($yamlFiles), []));

        foreach ($graph as $file => $dependencies) {
            foreach ($dependencies as $dep) {
                if (isset($adjacencyList[$dep])) {
                    $adjacencyList[$dep][] = $file;
                    $inDegree[$file]++;
                }
            }
        }

        // Collect nodes with in-degree 0
        $queue = array_filter($yamlFiles, fn($file) => $inDegree[$file] === 0);
        $sorted = [];

        while (!empty($queue)) {
            $file = array_shift($queue);
            $sorted[] = $file;

            foreach ($adjacencyList[$file] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Check for circular dependencies
        if (count($sorted) !== count($yamlFiles)) {
            throw new RuntimeException(__d('cake_utility', 'Circular dependency detected in YAML scenario files'));
        }

        return $sorted;
    }

    /**
     * YAMLファイルパスからCakePHPテーブルエイリアスを解決する。
     *
     * ファイル名の先頭数字プレフィックス（例: "01_", "01-"）を除去してから
     * テーブル名形式に変換する。これにより、シーケンス番号付きファイルを
     * 整理しつつクリーンなテーブル名を維持できる。
     *
     * 例:
     *   01_groups.yml       → groups
     *   02_users.yml        → users
     *   03_shop_products.yml → shop_products
     *
     * @param string $filePath YAMLファイルの絶対パス
     * @return string テーブル名（CakePHPテーブルエイリアス）
     */
    protected function resolveTableNameFromFile(string $filePath): string
    {
        $basename = basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION));

        // Remove leading numeric prefix with underscore or hyphen (e.g., "01_", "01-", "02_", etc.)
        // This allows organizing scenario files with sequence numbers for clarity
        // Users can choose their preferred separator: underscore or hyphen
        $basename = preg_replace('/^\d+[_-]/', '', $basename);

        return Inflector::tableize($basename);
    }

    /**
     * シナリオ名から絶対ファイルパスを解決する。
     *
     * .yaml → .yml → ディレクトリの順で解決を試みる。
     *
     * @param string $scenarioName シナリオ名（拡張子なしのファイル名またはディレクトリ名）
     * @return string 解決された絶対パス
     * @throws \RuntimeException パスが解決できない場合
     */
    protected function resolvePath(string $scenarioName): string
    {
        // Try with .yaml extension
        $yamlPath = $this->basePath . DS . $scenarioName . '.yaml';
        if (is_file($yamlPath)) {
            return $yamlPath;
        }

        // Try with .yml extension
        $ymlPath = $this->basePath . DS . $scenarioName . '.yml';
        if (is_file($ymlPath)) {
            return $ymlPath;
        }

        // Try as directory
        $dirPath = $this->basePath . DS . $scenarioName;
        if (is_dir($dirPath)) {
            return $dirPath;
        }

        throw new RuntimeException(
            __d('cake_utility', 'Scenario not found: {0} (tried in {1})', $scenarioName, $this->basePath)
        );
    }

    /**
     * YAMLファイルをレコード配列にパースする。
     *
     * @param string $filePath YAMLファイルのパス
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException ファイルが読み取れないか無効な場合
     */
    protected function parseYaml(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException(
                __d('cake_utility', 'Scenario file not readable: {0}', $filePath)
            );
        }

        $loader = new Loader();
        return $loader->parse(file_get_contents($filePath));
    }
}
