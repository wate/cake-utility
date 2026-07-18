<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Import;

use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakeUtility\Import\CsvRowReader;
use CakeUtility\Import\ImportWorkflow;

/**
 * ImportWorkflowLookupTest
 *
 * ImportWorkflow の lookup 機能の結合テスト。
 * SQLiteの test データベースを使用し、実際に参照テーブルに対して
 * 一括SELECT→キャッシュ→マッピングが正しく動作することを検証する。
 */
class ImportWorkflowLookupTest extends TestCase
{
    private string $fixturePath;
    private Table $departmentsTable;
    private Table $employeesTable;

    public function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = dirname(dirname(__DIR__)) . DS . 'Fixture' . DS . 'data' . DS . 'import' . DS;

        $locator = new TableLocator();

        // import_departments テーブルを構成
        $this->departmentsTable = $locator->get('ImportDepartments', [
            'connectionName' => 'test',
        ]);

        // import_employees テーブルを構成
        $this->employeesTable = $locator->get('ImportEmployees', [
            'connectionName' => 'test',
        ]);

        // テストデータを投入
        $this->departmentsTable->deleteAll('1=1');
        $this->employeesTable->deleteAll('1=1');

        $departments = $this->departmentsTable->newEntities([
            ['name' => '営業部', 'created' => '2026-01-01 00:00:00'],
            ['name' => '開発部', 'created' => '2026-01-01 00:00:00'],
            ['name' => '経理部', 'created' => '2026-01-01 00:00:00'],
        ]);
        $this->departmentsTable->saveMany($departments);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->departmentsTable->deleteAll('1=1');
        $this->employeesTable->deleteAll('1=1');
    }

    public function testLookupResolvesToId(): void
    {
        // lookup 設定: CSVの「部署名」カラムを import_departments.name で検索し、id を department_id にセット
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $this->employeesTable,
            options: [
                'columnMap' => [
                    '名前' => 'name',
                    'メール' => 'email',
                    '部署名' => 'department_id',
                ],
                'lookup' => [
                    '部署名' => [
                        'table' => 'ImportDepartments',
                        'from' => 'name',
                        'default' => null,
                    ],
                ],
                'fixed' => [
                    'created' => '2026-07-18 00:00:00',
                ],
            ],
        );

        $preview = $workflow->preview($this->fixturePath . 'lookup_test.csv');

        $this->assertSame(0, $preview->errorCount(), 'lookup解決にエラーがないこと');

        $entities = $preview->validatedRows();
        $this->assertCount(3, $entities);

        $deptEigyo = $this->departmentsTable->find()->where(['name' => '営業部'])->first();
        $this->assertNotNull($deptEigyo);
        $this->assertSame($deptEigyo->id, $entities[0]->get('department_id'));

        $deptKaihatsu = $this->departmentsTable->find()->where(['name' => '開発部'])->first();
        $this->assertNotNull($deptKaihatsu);
        $this->assertSame($deptKaihatsu->id, $entities[1]->get('department_id'));
    }

    public function testLookupFallbackToDefault(): void
    {
        // 「総務部」は departments テーブルに存在しない → default 値がセットされる
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $this->employeesTable,
            options: [
                'columnMap' => [
                    '名前' => 'name',
                    'メール' => 'email',
                ],
                'lookup' => [
                    '部署名' => [
                        'table' => 'ImportDepartments',
                        'from' => 'name',
                        'default' => null,
                    ],
                ],
                'fixed' => [
                    'created' => '2026-07-18 00:00:00',
                ],
            ],
        );

        $preview = $workflow->preview($this->fixturePath . 'lookup_test.csv');

        $entities = $preview->validatedRows();

        $this->assertNull($entities[2]->get('department_id'), '存在しない部署名はnullになること');
    }

    public function testLookupWithExecute(): void
    {
        // import() で通しで実行し、実際にDBに保存されることを確認
        $workflow = new ImportWorkflow(
            reader: new CsvRowReader(encoding: 'UTF-8'),
            table: $this->employeesTable,
            options: [
                'columnMap' => [
                    '名前' => 'name',
                    'メール' => 'email',
                    '部署名' => 'department_id',
                ],
                'lookup' => [
                    '部署名' => [
                        'table' => 'ImportDepartments',
                        'from' => 'name',
                        'default' => null,
                    ],
                ],
                'fixed' => [
                    'created' => '2026-07-18 00:00:00',
                ],
            ],
        );

        $result = $workflow->import($this->fixturePath . 'lookup_test.csv');

        $this->assertSame(3, $result->successCount(), '3件すべて保存成功');
        $this->assertTrue($result->isSuccess());

        $saved = $this->employeesTable->find()->all();
        $this->assertCount(3, $saved);

        $deptEigyo = $this->departmentsTable->find()->where(['name' => '営業部'])->first();
        $savedYamada = $this->employeesTable->find()->where(['name' => '山田太郎'])->first();
        $this->assertSame($deptEigyo->id, $savedYamada->department_id);
    }
}
