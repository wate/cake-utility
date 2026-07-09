<?php

declare(strict_types=1);

namespace CakeUtility\Yaml;

use Cake\ORM\Table;
use Cake\Database\Schema\TableSchema;
use Symfony\Component\Yaml\Yaml;
use RuntimeException;
use DateTime;
use Exception;

/**
 * Yaml Loader
 *
 * YAML形式のデータを読み込み、DB投入可能な形式に変換・参照解決を行うコアクラス。
 * パース（構造化・正規化）と参照解決を分離し、ID確定タイミングを呼び出し側に委ねる。
 */
class Loader
{
    /**
     * @today 解決時の時間境界を設定します
     *
     * @param string $boundary 'start' または 'end'
     * @return $this
     */
    public function setTodayBoundary(string $boundary): self
    {
        $this->todayBoundary = $boundary;
        return $this;
    }

    /**
     * @today 解決時の時間境界 ('start' = 00:00:00, 'end' = 23:59:59)
     * @var string
     */
    protected string $todayBoundary = 'start';

    /**
     * YAMLファイルまたは文字列を読み込み、構造化データに変換する（パースフェーズ）
     *
     * @param string $input YAMLファイルの絶対パス、またはYAML文字列
     * @return array 構造化されたレコードリスト
     * @throws RuntimeException パース失敗時
     */
    public function parse(string $input): array
    {
        if (file_exists($input)) {
            $data = Yaml::parseFile($input);
        } else {
            $data = Yaml::parse($input);
        }

        if (!is_array($data)) {
            return [];
        }

        return $this->normalize($data);
    }

    /**
     * データの正規化（予約語解決）
     *
     * @param mixed $data
     * @return mixed
     */
    protected function normalize($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->normalize($value);
            }
            return $data;
        }

        if (is_string($data)) {
            // 日時予約語の解決 (@now, @today, @now +1 day 等)
            if (str_starts_with($data, '@')) {
                return $this->resolveDatetime($data);
            }
        }

        return $data;
    }

    /**
     * 日時予約語を実際の日時オブジェクトに変換する
     *
     * @param string $value
     * @return \DateTimeImmutable
     */
    protected function resolveDatetime(string $value): \DateTimeImmutable
    {
        $expression = substr($value, 1); // '@' を除く

        // キーワードの正規化
        $expression = str_replace(['now', 'today'], 'now', $expression);

        // @today の場合は時間を 00:00:00 に固定
        $isToday = str_contains($value, 'today');

        try {
            $date = new DateTime($expression);
            if ($isToday) {
                $date->setTime(
                    $this->todayBoundary === 'end' ? 23 : 0,
                    $this->todayBoundary === 'end' ? 59 : 0,
                    $this->todayBoundary === 'end' ? 59 : 0
                );
            }
            return \DateTimeImmutable::createFromMutable($date);
        } catch (Exception $e) {
            throw new RuntimeException(sprintf('Invalid datetime expression: %s', $value));
        }
    }

    /**
     * 参照ラベルを実際のID値に置換し、スキーマに基づいた型変換を行う（参照解決フェーズ）
     *
     * @param array $data 処理対象のデータ
     * @param array $refMap 参照マップ (label => id)
     * @param Table|TableSchema|null $context 型変換に使用するテーブルまたはスキーマオブジェクト
     * @return array 参照解決・型変換後のデータ
     * @throws RuntimeException 参照解決に失敗した場合
     */
    public function resolve(array $data, array $refMap, Table|TableSchema|null $context = null): array
    {
        $schema = null;
        if ($context instanceof Table) {
            $schema = $context->getSchema();
        } elseif ($context instanceof TableSchema) {
            $schema = $context;
        }
        $resolvedData = [];

        // 単一レコード (1次元連想配列) を検出して正規化
        $isSingleRecord = !empty($data) && !is_array(reset($data));
        $records = $isSingleRecord ? [$data] : $data;

        foreach ($records as $index => $record) {
            $resolvedRecord = [];
            foreach ($record as $key => $value) {
                // 制御用キー (_ref, _keys) は DB 投入データから除外する
                if (str_starts_with($key, '_')) {
                    continue;
                }

                $val = $this->resolveValue($value, $refMap);

                // スキーマが存在し、かつカラムが定義されている場合は型変換を行う
                if ($schema && $schema->hasColumn($key)) {
                    $val = $this->castValue($val, $schema->getColumn($key));
                }

                $resolvedRecord[$key] = $val;
            }
            $resolvedData[$index] = $resolvedRecord;
        }

        return $isSingleRecord ? ($resolvedData[0] ?? []) : $resolvedData;
    }

    /**
     * 個別の値を参照解決する
     *
     * @param mixed $value
     * @param array $refMap
     * @return mixed
     * @throws RuntimeException
     */
    protected function resolveValue($value, array $refMap)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->resolveValue($v, $refMap);
            }
            return $result;
        }

        if (is_string($value) && str_starts_with($value, 'ref:')) {
            $refName = substr($value, 4);
            if (!array_key_exists($refName, $refMap)) {
                throw new RuntimeException(sprintf('Reference "%s" not found', $refName));
            }
            return $refMap[$refName];
        }

        return $value;
    }

    /**
     * スキーマ定義に基づいて値をキャストする
     *
     * @param mixed $value
     * @param \Cake\Database\Schema\Column $column
     * @return mixed
     */
    protected function castValue($value, array $column)
    {
        if ($value === null) {
            return null;
        }

        $type = $column['type'] ?? null;

        if ($type === null) {
            return $value;
        }

        // Integer 型の正規化
        if ($type === 'integer' || $type === 'biginteger') {
            return (int)$value;
        }

        // DateTime 型の正規化
        if (($type === 'datetime' || $type === 'timestamp') && is_string($value)) {
            return new \DateTimeImmutable($value);
        }

        // Boolean 型の正規化 (0/1 <-> true/false)
        if ($type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        // JSON 型の正規化 (文字列 -> 配列/オブジェクト)
        if ($type === 'json' && is_string($value)) {
            $decoded = json_decode($value, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
        }

        return $value;
    }
}
