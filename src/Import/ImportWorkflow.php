<?php

declare(strict_types=1);

namespace CakeUtility\Import;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\Validation\Validator;

use function Cake\I18n\__d;

/**
 * ImportWorkflow
 *
 * ワークフローの進行役。Controllerから呼ばれる窓口。
 * 対象テーブルはコンストラクタで受け取り、Tableの標準機能(newEntity()/save())を利用する。
 *
 * 提供メソッド:
 * - preview(): パース+バリデーション。保存は行わず PreviewResult を返す
 * - execute(): バリデーション済みエンティティを保存する
 * - import(): preview() + execute() のショートカット
 */
class ImportWorkflow
{
    /**
     * @var RowReaderInterface フォーマットリーダー
     */
    private RowReaderInterface $reader;

    /**
     * @var Table 対象テーブル（インポート先のModel）
     */
    private Table $table;

    /**
     * @var array<string, string> CSVカラム名 => モデルカラム名 のマッピング
     */
    private array $columnMap;

    /**
     * @var array<string, mixed> 全行に共通でセットする値（値がcallableの場合は行データを受け取って評価）
     */
    private array $fixed;

    /** @var mixed FKバッチ解決の指定（配列またはcallable）。配列形式/インラインマップ形式/コールバック形式に対応 */
    private mixed $lookup;

    /**
     * @var callable|null バリデーション前の汎用加工
     */
    private $beforeMarshal;

    /**
     * @var callable|null 保存前のエンティティ加工
     */
    private $beforeSave;

    /**
     * @var callable|null 保存完了後のフック
     */
    private $afterSave;

    /**
     * @var string|Validator|null バリデーションルールセット
     */
    private string|Validator|null $validate;

    /**
     * @var array<string, mixed> newEntity() に渡す追加オプション
     */
    private array $newEntityOptions;

    /**
     * @var int 保存時のバッチ分割サイズ（0または未指定で分割なし）
     */
    private int $batchSize;

    /**
     * @var callable|null 行フィルター（falseを返す行をスキップ）
     */
    private $rowFilter;

    /**
     * @var array<string>|null upsert用キーカラム名の配列
     */
    private ?array $upsertKeys;

    /**
     * @param RowReaderInterface $reader フォーマットリーダー
     * @param \Cake\ORM\Table $table 対象テーブル(インポート先のModel)
     * @param array $options ワークフローオプション
     *   - columnMap (array): CSVカラム名 => モデルカラム名 のマッピング(省略時は同名)
     *   - fixed (array): 全行に共通でセットする値(例: ['is_public' => true])\n     *     値がcallableの場合は行データ($row)を受け取って評価する(例: ['user_id' => fn($row) => $auth->id])
     *   - lookup (array|null): FKバッチ解決の指定
     *     配列形式（DB検索）: CSV列名 => ['table' => Table名, 'from' => 検索カラム, 'default' => null, 'conditions' => [フィルタ条件]]
     *     conditions には WHERE句の追加条件を指定できる(例: ['is_active' => true])
     *     解決したIDは同じCSV列名キーに上書き。columnMapでモデルカラム名に変換する
     *     配列形式（インラインマップ）: CSV列名 => [値 => ID, ...]
     *     テーブルを作るまでもない小さなマッピングに利用
     *     コールバック形式: CSV列名 => function(array $values, Table $sourceTable): array
     *     動的な検索が必要な場合に利用。$values(ユニーク値配列)から連想配列[値=>ID]を返す
     *   - beforeMarshal (callable|null): バリデーション前の汎用加工
     *     行データを受け取り、加工後の配列を返す。lookupで足りないケースに用いる
     *   - beforeSave (callable|null): 保存前の加工
     *     エンティティを受け取り、加工後のエンティティを返す
     *   - afterSave (callable|null): 保存完了後のフック
     *     保存成功後のエンティティを受け取り呼ばれる。戻り値は無視
     *   - validate (string|Validator): 使用するバリデーションルールセット名
     *     ('default' / 'import' など) または Validator オブジェクトを直接指定
     *   - newEntityOptions (array): Table::newEntity() に渡す追加オプション
     *     (例: ['accessibleFields' => ['id' => true], 'associated' => ['Profiles']])
     *   - batchSize (int): 保存時のバッチ分割サイズ。0で分割なし(デフォルト)
     *     例: 1000 で1000行ごとに分割保存
     *   - rowFilter (callable|null): 行フィルター。fn(array $row): bool
     *     falseを返す行を処理対象外とする
     *   - upsertKeys (array|null): upsert用キーカラム名の配列
     *     例: ['email'] でemailが一致すれば更新、なければ新規作成
     */
    public function __construct(
        RowReaderInterface $reader,
        Table $table,
        array $options = [],
    ) {
        $this->reader = $reader;
        $this->table = $table;
        $this->columnMap = $options['columnMap'] ?? [];
        $this->fixed = $options['fixed'] ?? [];
        $this->lookup = $options['lookup'] ?? null;
        $this->beforeMarshal = $options['beforeMarshal'] ?? null;
        $this->beforeSave = $options['beforeSave'] ?? null;
        $this->afterSave = $options['afterSave'] ?? null;
        $this->validate = $options['validate'] ?? 'default';
        $this->newEntityOptions = $options['newEntityOptions'] ?? [];
        $this->batchSize = (int)($options['batchSize'] ?? 0);
        $this->rowFilter = $options['rowFilter'] ?? null;
        $this->upsertKeys = $options['upsertKeys'] ?? null;
    }

    /**
     * パース+バリデーションを実行。保存は行わない。
     *
     * @param string $filePath ファイルパス
     * @return PreviewResult プレビュー結果
     */
    public function preview(string $filePath): PreviewResult
    {
        $this->reader->open($filePath);
        $headers = $this->reader->headers();

        // 全行を収集
        $allRows = [];
        foreach ($this->reader->rows() as $row) {
            $allRows[] = $row;
        }
        $errors = $this->reader->errors();
        $total = count($allRows) + count($errors);

        // lookup用のバッチ解決
        $lookupCache = $this->buildLookupCache($allRows);

        // 各行を処理
        $entities = [];
        $validatedErrors = [];

        foreach ($allRows as $index => $row) {
            // rowFilter: 条件に合わない行をスキップ
            if ($this->rowFilter !== null && !($this->rowFilter)($row)) {
                continue;
            }

            // lookup: FKバッチ解決（CSV列名キーにIDを上書き）
            if ($this->lookup !== null) {
                $row = $this->applyLookup($row, $lookupCache);
            }

            // columnMap: 列名変換（lookupで解決済みのキーも含めてマッピング）
            if (!empty($this->columnMap)) {
                $row = $this->applyColumnMap($row);
            }

            // fixed: 固定値追加（値がcallableの場合は行データを受け取って評価）
            foreach ($this->fixed as $key => $value) {
                $row[$key] = is_callable($value) ? $value($row) : $value;
            }

            // beforeMarshal: バリデーション前の加工
            if ($this->beforeMarshal !== null) {
                $row = ($this->beforeMarshal)($row);
            }

            // newEntity または upsert: エンティティ生成+バリデーション
            $validator = $this->resolveValidator();
            $newOptions = array_merge($this->newEntityOptions, ['validate' => $validator]);

            // upsertKeys が指定されていれば既存レコードを検索
            if ($this->upsertKeys !== null) {
                $conditions = [];
                foreach ($this->upsertKeys as $key) {
                    if (isset($row[$key])) {
                        $conditions[$key] = $row[$key];
                    }
                }
                if (!empty($conditions)) {
                    $existing = $this->table->find()->where($conditions)->first();
                    if ($existing !== null) {
                        // 既存レコードがあれば patchEntity（更新）
                        $entity = $this->table->patchEntity($existing, $row, $newOptions);
                    } else {
                        $entity = $this->table->newEntity($row, $newOptions);
                    }
                } else {
                    $entity = $this->table->newEntity($row, $newOptions);
                }
            } else {
                $entity = $this->table->newEntity($row, $newOptions);
            }

            if ($entity->hasErrors()) {
                $errorMessages = [];
                foreach ($entity->getErrors() as $field => $fieldErrors) {
                    $errorMessages[] = implode(', ', $fieldErrors);
                }
                $validatedErrors[] = [
                    'row' => $index + 2, // 1行目がヘッダーのため+2
                    'message' => implode('; ', $errorMessages),
                    'data' => $row,
                ];
            } else {
                $entities[] = $entity;
            }
        }

        // パースエラーも結合
        $allErrors = array_merge($errors, $validatedErrors);

        return new PreviewResult($entities, $total, $allErrors);
    }

    /**
     * バリデーション済みエンティティを保存する。
     *
     * @param array<EntityInterface> $entities バリデーション済みエンティティ
     * @return ImportResult インポート結果
     */
    public function execute(array $entities): ImportResult
    {
        $saved = [];
        $errors = [];

        // batchSize が指定されていれば分割して処理
        $chunks = $this->batchSize > 0 ? array_chunk($entities, $this->batchSize) : [$entities];
        $globalIndex = 0;

        foreach ($chunks as $chunk) {
            foreach ($chunk as $entity) {
                // beforeSave: 保存前の加工
                if ($this->beforeSave !== null) {
                    $entity = ($this->beforeSave)($entity);
                }

                try {
                    $result = $this->table->save($entity);
                    if ($result !== false) {
                        // afterSave: 保存完了後のフック
                        if ($this->afterSave !== null) {
                            ($this->afterSave)($result);
                        }
                        $saved[] = $result;
                    } else {
                        $errorMessages = [];
                        if ($entity->hasErrors()) {
                            foreach ($entity->getErrors() as $field => $fieldErrors) {
                                $errorMessages[] = implode(', ', $fieldErrors);
                            }
                        }
                        $errors[] = [
                            'row' => $globalIndex + 2,
                            'message' => $errorMessages !== []
                                ? implode('; ', $errorMessages)
                                : __d('cake_utility', 'Failed to save record'),
                            'data' => $entity->toArray(),
                        ];
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $globalIndex + 2,
                        'message' => $e->getMessage(),
                        'data' => $entity->toArray(),
                    ];
                }

                $globalIndex++;
            }
        }

        return new ImportResult($saved, $errors);
    }

    /**
     * preview() + execute() のショートカット。
     *
     * @param string $filePath ファイルパス
     * @return ImportResult インポート結果
     */
    public function import(string $filePath): ImportResult
    {
        $preview = $this->preview($filePath);

        return $this->execute($preview->validatedRows());
    }

    /**
     * columnMapを適用して列名を変換する。
     *
     * @param array<string, mixed> $row 行データ
     * @return array<string, mixed> 変換後の行データ
     */
    private function applyColumnMap(array $row): array
    {
        $mapped = [];
        foreach ($row as $csvColumn => $value) {
            $modelColumn = $this->columnMap[$csvColumn] ?? $csvColumn;
            $mapped[$modelColumn] = $value;
        }

        return $mapped;
    }

    /**
     * lookupキャッシュを構築する。
     *
     * @param array<array<string, mixed>> $rows 全行データ
     * @return array<string, array<string, mixed>> キャッシュ
     */
    private function buildLookupCache(array $rows): array
    {
        if ($this->lookup === null) {
            return [];
        }

        $cache = [];
        $connection = $this->table->getConnection();

        foreach ($this->lookup as $csvColumn => $config) {
            // 全行からユニーク値を収集
            $values = [];
            foreach ($rows as $row) {
                if (isset($row[$csvColumn]) && $row[$csvColumn] !== '') {
                    $values[] = (string)$row[$csvColumn];
                }
            }
            $values = array_unique($values);

            if (empty($values)) {
                $cache[$csvColumn] = [];
                continue;
            }

            // コールバック形式: 動的な解決を委譲
            if (is_callable($config)) {
                try {
                    $resultMap = $config($values, $this->table);
                    $cache[$csvColumn] = $resultMap;
                    $cache[$csvColumn]['__default'] = null;
                } catch (\Exception $e) {
                    $cache[$csvColumn] = [];
                    $cache[$csvColumn]['__default'] = null;
                }
                continue;
            }

            // インラインマップ形式: シンプルな連想配列 [値 => ID, ...]
            if (is_array($config) && !isset($config['table'])) {
                $cache[$csvColumn] = $config;
                $cache[$csvColumn]['__default'] = null;
                continue;
            }

            // DB検索形式: 静的な設定に基づくバッチ解決
            try {
                $tableName = $config['table'];
                $from = $config['from'];
                $default = $config['default'] ?? null;
                $conditions = $config['conditions'] ?? [];
                $locator = new TableLocator();
                $lookupTable = $locator->get($tableName, ['connection' => $connection]);
                $query = $lookupTable->find()
                    ->select([$from, 'id'])
                    ->where([$from . ' IN' => $values]);
                foreach ($conditions as $field => $val) {
                    $query = $query->andWhere([$field => $val]);
                }
                $results = $query->all();

                $lookupMap = [];
                foreach ($results as $record) {
                    $lookupMap[(string)$record->get($from)] = $record->get('id');
                }
                $cache[$csvColumn] = $lookupMap;
                $cache[$csvColumn]['__default'] = $default;
            } catch (\Exception $e) {
                $cache[$csvColumn] = [];
                $cache[$csvColumn]['__default'] = $default ?? null;
            }
        }

        return $cache;
    }

    /**
     * lookupキャッシュを適用する。
     *
     * @param array<string, mixed> $row 行データ
     * @param array<string, array<string, mixed>> $cache lookupキャッシュ
     * @return array<string, mixed> 変換後の行データ
     */
    private function applyLookup(array $row, array $cache): array
    {
        foreach ($this->lookup as $csvColumn => $config) {
            if (!isset($row[$csvColumn])) {
                continue;
            }

            $value = (string)$row[$csvColumn];
            $lookupMap = $cache[$csvColumn] ?? [];
            $default = $lookupMap['__default'] ?? null;

            // 解決したIDを同じCSV列名キーに上書き（columnMapでモデルカラム名に変換される）
            $row[$csvColumn] = isset($lookupMap[$value]) ? $lookupMap[$value] : $default;
        }

        return $row;
    }

    /**
     * バリデーターを解決する。
     *
     * @return Validator|string
     */
    private function resolveValidator(): Validator|string
    {
        return $this->validate ?? 'default';
    }
}
