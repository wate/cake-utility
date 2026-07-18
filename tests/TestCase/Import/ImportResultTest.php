<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Import;

use Cake\TestSuite\TestCase;
use CakeUtility\Import\ImportResult;
use Cake\Datasource\EntityInterface;

/**
 * ImportResultTest
 *
 * 値オブジェクトの振る舞いを検証する。
 */
class ImportResultTest extends TestCase
{
    /**
     * 成功のみの結果を検証する。
     *
     * @return void
     */
    public function testSuccessOnly(): void
    {
        $entity = $this->createMock(EntityInterface::class);

        $result = new ImportResult([$entity]);

        $this->assertSame(1, $result->successCount());
        $this->assertSame(0, $result->errorCount());
        $this->assertCount(1, $result->savedEntities());
        $this->assertTrue($result->isSuccess());
    }

    /**
     * エラーを含む結果を検証する。
     *
     * @return void
     */
    public function testWithErrors(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $errors = [
            ['row' => 2, 'message' => '保存に失敗しました', 'data' => []],
        ];

        $result = new ImportResult([$entity], $errors);

        $this->assertSame(1, $result->successCount());
        $this->assertSame(1, $result->errorCount());
        $this->assertFalse($result->isSuccess());
        $this->assertSame($errors, $result->rowErrors());
    }

    /**
     * 全件失敗の結果を検証する。
     *
     * @return void
     */
    public function testAllFailed(): void
    {
        $errors = [
            ['row' => 2, 'message' => 'エラー1', 'data' => []],
            ['row' => 3, 'message' => 'エラー2', 'data' => []],
        ];

        $result = new ImportResult([], $errors);

        $this->assertSame(0, $result->successCount());
        $this->assertSame(2, $result->errorCount());
        $this->assertCount(0, $result->savedEntities());
        $this->assertFalse($result->isSuccess());
    }
}
