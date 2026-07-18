<?php

declare(strict_types=1);

namespace CakeUtility\Import;

use Cake\Datasource\EntityInterface;

/**
 * PreviewResult
 *
 * プレビュー結果値オブジェクト。
 * ImportWorkflow::preview() の戻り値として、パース+バリデーション後の状態を保持する。
 */
class PreviewResult
{
    /**
     * バリデーション済みエンティティの配列
     *
     * @var array<\Cake\Datasource\EntityInterface>
     */
    private array $entities;

    /**
     * 総行数
     *
     * @var int
     */
    private int $total;

    /**
     * エラー行数
     *
     * @var int
     */
    private int $errorCount;

    /**
     * エラー行の情報
     *
     * @var array<int, array{row: int, message: string, data: array<string, mixed>}>
     */
    private array $errors;

    /**
     * @param array<EntityInterface> $entities バリデーション済みエンティティ
     * @param int $total 総行数
     * @param array<int, array{row: int, message: string, data: array<string, mixed>}> $errors エラー行の情報
     */
    public function __construct(
        array $entities,
        int $total = 0,
        array $errors = [],
    ) {
        $this->entities = $entities;
        $this->total = $total;
        $this->errors = $errors;
        $this->errorCount = count($errors);
    }

    /**
     * バリデーション済みエンティティを取得する。
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function validatedRows(): array
    {
        return $this->entities;
    }

    /**
     * 総行数を取得する。
     *
     * @return int
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * エラー行数を取得する。
     *
     * @return int
     */
    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * エラー行の情報を取得する。
     *
     * @return array<int, array{row: int, message: string, data: array<string, mixed>}>
     */
    public function rowErrors(): array
    {
        return $this->errors;
    }
}
