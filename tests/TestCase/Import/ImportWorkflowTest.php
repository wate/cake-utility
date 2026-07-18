<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Import;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Cake\Validation\Validator;
use CakeUtility\Import\CsvRowReader;
use CakeUtility\Import\ImportWorkflow;

/**
 * ImportWorkflowTest
 *
 * preview() / execute() / import() の各メソッドを検証する。
 */
class ImportWorkflowTest extends TestCase
{
    private string $fixturePath;

    public function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = dirname(dirname(__DIR__)) . DS . 'Fixture' . DS . 'data' . DS . 'import' . DS;
    }

    public function testImportReturnsImportResult(): void
    {
        $table = $this->createMock(Table::class);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);

        $table->method('newEntity')
            ->willReturn($entity);

        $table->method('save')
            ->willReturn($entity);

        // CsvRowReader→preview→execute の流れ
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
        );

        $result = $workflow->import($this->fixturePath . 'utf8.csv');

        $this->assertInstanceOf(\CakeUtility\Import\ImportResult::class, $result);
    }

    public function testPreviewReturnsPreviewResult(): void
    {
        $table = $this->createMock(Table::class);

        $table->method('newEntity')
            ->willReturnCallback(function (array $data, array $options) {
                $entity = $this->createMock(EntityInterface::class);
                $entity->method('hasErrors')->willReturn(false);

                return $entity;
            });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
        );

        $preview = $workflow->preview($this->fixturePath . 'utf8.csv');

        $this->assertInstanceOf(\CakeUtility\Import\PreviewResult::class, $preview);
        $this->assertSame(3, $preview->total());
    }

    public function testExecuteSavesEntities(): void
    {
        $entity = $this->createMock(EntityInterface::class);

        $table = $this->createMock(Table::class);
        $table->method('save')
            ->willReturn($entity);

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
        );

        $result = $workflow->execute([$entity]);

        $this->assertSame(1, $result->successCount());
        $this->assertSame(0, $result->errorCount());
        $this->assertTrue($result->isSuccess());
    }

    public function testExecuteWithSaveFailure(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);
        $entity->method('toArray')->willReturn(['title' => 'test']);

        $table = $this->createMock(Table::class);
        $table->method('save')
            ->willReturn(false);

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
        );

        $result = $workflow->execute([$entity]);

        $this->assertSame(0, $result->successCount());
        $this->assertSame(1, $result->errorCount());
        $this->assertFalse($result->isSuccess());
    }

    public function testColumnMap(): void
    {
        $table = $this->createMock(Table::class);
        $capturedData = [];

        $table->method('newEntity')
            ->willReturnCallback(function (array $data, array $options) use (&$capturedData) {
                $capturedData = $data;
                $entity = $this->createMock(EntityInterface::class);
                $entity->method('hasErrors')->willReturn(false);

                return $entity;
            });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
            options: [
                'columnMap' => [
                    'タイトル' => 'title',
                    '説明' => 'description',
                    'ステータス' => 'status',
                ],
            ],
        );

        $preview = $workflow->preview($this->fixturePath . 'utf8.csv');
        $entities = $preview->validatedRows();

        $this->assertCount(3, $entities);
        // newEntity が columnMap 適用後に呼ばれていることを確認
        $this->assertArrayHasKey('title', $capturedData);
        $this->assertArrayHasKey('description', $capturedData);
    }

    public function testFixedValues(): void
    {
        $table = $this->createMock(Table::class);
        $capturedData = [];

        $table->method('newEntity')
            ->willReturnCallback(function (array $data, array $options) use (&$capturedData) {
                $capturedData = $data;
                $entity = $this->createMock(EntityInterface::class);
                $entity->method('hasErrors')->willReturn(false);

                return $entity;
            });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
            options: [
                'fixed' => [
                    'user_id' => 1,
                ],
            ],
        );

        $workflow->preview($this->fixturePath . 'utf8.csv');

        // fixed値が適用されていることを確認
        $this->assertSame(1, $capturedData['user_id']);
    }

    public function testBeforeMarshal(): void
    {
        $table = $this->createMock(Table::class);
        $capturedData = [];

        $table->method('newEntity')
            ->willReturnCallback(function (array $data, array $options) use (&$capturedData) {
                $capturedData = $data;
                $entity = $this->createMock(EntityInterface::class);
                $entity->method('hasErrors')->willReturn(false);

                return $entity;
            });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
            options: [
                'beforeMarshal' => function (array $row) {
                    $row['processed'] = true;

                    return $row;
                },
            ],
        );

        $workflow->preview($this->fixturePath . 'utf8.csv');

        // beforeMarshalが適用されていることを確認
        $this->assertTrue($capturedData['processed']);
    }

    public function testBeforeSave(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);
        $entity->method('toArray')->willReturn(['title' => 'test']);

        $table = $this->createMock(Table::class);
        $capturedEntity = null;

        $table->method('save')
            ->willReturnCallback(function ($e) use (&$capturedEntity) {
                $capturedEntity = $e;

                return $e;
            });

        $processedEntity = $this->createMock(EntityInterface::class);

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'utf-8'),
            table: $table,
            options: [
                'beforeSave' => function ($e) use ($processedEntity) {
                    return $processedEntity;
                },
            ],
        );

        $workflow->execute([$entity]);

        // beforeSaveで返されたエンティティがsaveに渡されていることを確認
        $this->assertSame($processedEntity, $capturedEntity);
    }

    public function testAfterSaveIsCalled(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);
        $entity->method('toArray')->willReturn(['title' => 'test']);

        $table = $this->createMock(Table::class);
        $table->method('save')->willReturn($entity);

        $afterSaveCalled = false;
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'afterSave' => function ($e) use (&$afterSaveCalled) {
                    $afterSaveCalled = true;
                },
            ],
        );

        $workflow->execute([$entity]);

        $this->assertTrue($afterSaveCalled, 'afterSaveが保存成功後に呼ばれること');
    }

    public function testAfterSaveNotCalledOnFailure(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);
        $entity->method('toArray')->willReturn(['title' => 'test']);

        $table = $this->createMock(Table::class);
        $table->method('save')->willReturn(false);

        $afterSaveCalled = false;
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'afterSave' => function ($e) use (&$afterSaveCalled) {
                    $afterSaveCalled = true;
                },
            ],
        );

        $workflow->execute([$entity]);

        $this->assertFalse($afterSaveCalled, '保存失敗時はafterSaveが呼ばれないこと');
    }

    public function testRowFilterSkipsRows(): void
    {
        $table = $this->createMock(Table::class);

        $table->method('newEntity')
            ->willReturnCallback(function (array $data, array $options) {
                $entity = $this->createMock(EntityInterface::class);
                $entity->method('hasErrors')->willReturn(false);
                return $entity;
            });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'rowFilter' => function (array $row) {
                    // タイトルが「テスト記事2」の行のみスキップ（false）
                    return $row['タイトル'] !== 'テスト記事2';
                },
            ],
        );

        $preview = $workflow->preview($this->fixturePath . 'utf8.csv');

        // 3行中、テスト記事2がスキップされるので2行のみ
        $this->assertCount(2, $preview->validatedRows());
    }

    public function testBatchSizeProcessesAllRows(): void
    {
        $entity1 = $this->createMock(EntityInterface::class);
        $entity1->method('hasErrors')->willReturn(false);
        $entity1->method('toArray')->willReturn(['title' => 'a']);

        $entity2 = $this->createMock(EntityInterface::class);
        $entity2->method('hasErrors')->willReturn(false);
        $entity2->method('toArray')->willReturn(['title' => 'b']);

        $entity3 = $this->createMock(EntityInterface::class);
        $entity3->method('hasErrors')->willReturn(false);
        $entity3->method('toArray')->willReturn(['title' => 'c']);

        $table = $this->createMock(Table::class);
        $saveCount = 0;
        $table->method('save')->willReturnCallback(function ($e) use (&$saveCount) {
            $saveCount++;
            return $e;
        });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'batchSize' => 2, // 2件ずつ分割
            ],
        );

        $result = $workflow->execute([$entity1, $entity2, $entity3]);

        $this->assertSame(3, $saveCount, 'batchSize分割でも全件保存されること');
        $this->assertSame(3, $result->successCount());
    }

    public function testUpsertKeysUpdatesExisting(): void
    {
        $existingEntity = $this->createMock(EntityInterface::class);
        $existingEntity->method('hasErrors')->willReturn(false);

        $table = $this->createMock(Table::class);

        // find() で既存レコードが見つかる
        $query = $this->createMock(\Cake\ORM\Query\SelectQuery::class);
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn($existingEntity);

        $table->method('find')->willReturn($query);
        $table->method('patchEntity')->willReturn($existingEntity);

        $saveCalled = false;
        $table->method('save')->willReturnCallback(function ($e) use (&$saveCalled) {
            $saveCalled = true;
            return $e;
        });

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'upsertKeys' => ['email'],
            ],
        );

        // previewでupsertが動作することを確認
        // 既存レコードがあるのでpatchEntityが呼ばれる
        $preview = $workflow->preview($this->fixturePath . 'utf8.csv');
        $entities = $preview->validatedRows();

        // バリデーション通過したエンティティが返ってくる
        $this->assertCount(3, $entities);
    }

    public function testUpsertKeysInsertsNew(): void
    {
        $table = $this->createMock(Table::class);

        // find() で既存レコードが見つからない
        $query = $this->createMock(\Cake\ORM\Query\SelectQuery::class);
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn(null);

        $table->method('find')->willReturn($query);

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('hasErrors')->willReturn(false);
        $table->method('newEntity')->willReturn($entity);

        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $table,
            options: [
                'upsertKeys' => ['email'],
            ],
        );

        // 既存レコードがないのでnewEntityが呼ばれる
        $preview = $workflow->preview($this->fixturePath . 'utf8.csv');

        $this->assertCount(3, $preview->validatedRows());
    }
}
