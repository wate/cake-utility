<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Import;

use Cake\TestSuite\TestCase;
use CakeUtility\Import\PreviewResult;
use Cake\Datasource\EntityInterface;

/**
 * PreviewResultTest
 *
 * 値オブジェクトの振る舞いを検証する。
 */
class PreviewResultTest extends TestCase
{
    public function testConstructAndAccessors(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $errors = [
            ['row' => 2, 'message' => '必須項目です', 'data' => ['title' => '']],
        ];

        $result = new PreviewResult([$entity], 1, $errors);

        $this->assertCount(1, $result->validatedRows());
        $this->assertSame(1, $result->total());
        $this->assertSame(1, $result->errorCount());
        $this->assertSame($errors, $result->rowErrors());
    }

    public function testEmptyResult(): void
    {
        $result = new PreviewResult([]);

        $this->assertCount(0, $result->validatedRows());
        $this->assertSame(0, $result->total());
        $this->assertSame(0, $result->errorCount());
        $this->assertSame([], $result->rowErrors());
    }

    public function testMultipleErrors(): void
    {
        $entity1 = $this->createMock(EntityInterface::class);
        $entity2 = $this->createMock(EntityInterface::class);
        $errors = [
            ['row' => 2, 'message' => 'エラー1', 'data' => []],
            ['row' => 3, 'message' => 'エラー2', 'data' => []],
        ];

        $result = new PreviewResult([$entity1, $entity2], 4, $errors);

        $this->assertCount(2, $result->validatedRows());
        $this->assertSame(4, $result->total());
        $this->assertSame(2, $result->errorCount());
    }
}
