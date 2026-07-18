<?php

declare(strict_types=1);

namespace CakeUtility\Import;

use Cake\Datasource\EntityInterface;

/**
 * ImportResult
 *
 * インポート結果値オブジェクト。
 * ImportWorkflow::execute() および import() の戻り値として、保存結果を保持する。
 */
class ImportResult
{
    /**
     * 成功件数
     *
     * @var int
     */
    private int $successCount;

    /**
     * エラー件数
     *
     * @var int
     */
    private int $errorCount;

    /**
     * 保存成功したエンティティの配列
     *
     * @var array<\Cake\Datasource\EntityInterface>
     */
    private array $entities;

    /**
     * エラー行の情報
     *
     * @var array<int, array{row: int, message: string, data: array<string, mixed>}>
     */
    private array $errors;

    /**
     * @param array<EntityInterface> $entities 保存成功したエンティティ
     * @param array<int, array{row: int, message: string, data: array<string, mixed>}> $errors エラー行の情報
     */
    public function __construct(
        array $entities,
        array $errors = [],
    ) {
        $this->entities = $entities;
        $this->errors = $errors;
        $this->successCount = count($entities);
        $this->errorCount = count($errors);
    }

    /**
     * 成功件数を取得する。
     *
     * @return int
     */
    public function successCount(): int
    {
        return $this->successCount;
    }

    /**
     * エラー件数を取得する。
     *
     * @return int
     */
    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * 保存成功したエンティティを取得する。
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function savedEntities(): array
    {
        return $this->entities;
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

    /**
     * 完全成功(エラー0件)かどうかを返す。
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->errorCount === 0;
    }
}
