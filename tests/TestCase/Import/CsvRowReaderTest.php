<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Import;

use Cake\TestSuite\TestCase;
use CakeUtility\Import\CsvRowReader;

/**
 * CsvRowReaderTest
 *
 * CSVパースの各種ケースを検証する。
 */
class CsvRowReaderTest extends TestCase
{
    /**
     * テストフィクスチャファイルのディレクトリパス
     *
     * @var string
     */
    private string $fixturePath;

    /**
     * テスト前処理
     *
     * フィクスチャファイルのパスを設定する。
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = dirname(dirname(__DIR__)) . DS . 'Fixture' . DS . 'data' . DS . 'import' . DS;
    }

    public function testReadUtf8Csv(): void
    {
        $reader = new CsvRowReader(encoding: 'UTF-8');
        $reader->open($this->fixturePath . 'utf8.csv');

        $headers = $reader->headers();
        $this->assertSame(['タイトル', '説明', 'ステータス'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(3, $rows);
        $this->assertSame('テスト記事1', $rows[0]['タイトル']);
        $this->assertSame('公開', $rows[0]['ステータス']);

        $reader->close();
    }

    public function testReadShiftJisCsv(): void
    {
        $reader = new CsvRowReader(encoding: 'SJIS');
        $reader->open($this->fixturePath . 'sjis.csv');

        $headers = $reader->headers();
        $this->assertSame(['タイトル', '説明', 'ステータス'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(2, $rows);
        // Shift_JISからUTF-8に変換されていることを確認
        $this->assertSame('表題1', $rows[0]['タイトル']);
        $this->assertSame('商品A', $rows[1]['タイトル']);

        $reader->close();
    }

    public function testSkipEmptyRows(): void
    {
        $reader = new CsvRowReader(encoding: 'UTF-8');
        $reader->open($this->fixturePath . 'with_empty.csv');

        $rows = iterator_to_array($reader->rows());
        // 空行はスキップされるが、値のある行は残る
        $this->assertCount(5, $rows);
    }

    public function testInvalidColumns(): void
    {
        $reader = new CsvRowReader(encoding: 'UTF-8');
        $reader->open($this->fixturePath . 'invalid_columns.csv');

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(0, $rows, 'カラム数不一致の行は空行としてスキップ');

        $errors = $reader->errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Column count mismatch', $errors[0]['message']);
    }

    public function testFileNotFound(): void
    {
        $this->expectException(\RuntimeException::class);

        $reader = new CsvRowReader();
        $reader->open('/nonexistent/file.csv');
    }

    public function testHeadersBeforeOpen(): void
    {
        $this->expectException(\RuntimeException::class);

        $reader = new CsvRowReader();
        $reader->headers();
    }

    public function testRowsBeforeOpen(): void
    {
        $this->expectException(\RuntimeException::class);

        $reader = new CsvRowReader();
        $rows = $reader->rows();
        $rows->current(); // Generatorを実際に実行して例外をトリガー
    }

    public function testCloseAndReopen(): void
    {
        $reader = new CsvRowReader(encoding: 'UTF-8');
        $reader->open($this->fixturePath . 'utf8.csv');
        $headers1 = $reader->headers();
        $reader->close();

        $reader->open($this->fixturePath . 'utf8.csv');
        $headers2 = $reader->headers();
        $this->assertSame($headers1, $headers2);

        $reader->close();
    }

    public function testCustomDelimiter(): void
    {
        $tsvPath = $this->fixturePath . 'test.tsv';
        file_put_contents($tsvPath, "タイトル\t説明\n記事1\t内容1\n");

        $reader = new CsvRowReader(encoding: 'UTF-8', delimiter: "\t");
        $reader->open($tsvPath);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(1, $rows);
        $this->assertSame('記事1', $rows[0]['タイトル']);

        $reader->close();
        unlink($tsvPath);
    }

    public function testAutoDetectUtf8(): void
    {
        $reader = new CsvRowReader(); // encoding='auto'（デフォルト）
        $reader->open($this->fixturePath . 'utf8.csv');

        $headers = $reader->headers();
        $this->assertSame(['タイトル', '説明', 'ステータス'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(3, $rows);

        $reader->close();
    }

    public function testAutoDetectShiftJis(): void
    {
        $reader = new CsvRowReader(); // encoding='auto'（デフォルト）
        $reader->open($this->fixturePath . 'sjis.csv');

        $headers = $reader->headers();
        $this->assertSame(['タイトル', '説明', 'ステータス'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(2, $rows);
        $this->assertSame('表題1', $rows[0]['タイトル']);
        $this->assertSame('商品A', $rows[1]['タイトル']);

        $reader->close();
    }

    public function testMultipleHeaderRows(): void
    {
        $reader = new CsvRowReader(encoding: 'UTF-8', headerRows: 2);
        $reader->open($this->fixturePath . 'multi_header.csv');

        // カラム名は2行目（最終行）を採用
        $headers = $reader->headers();
        $this->assertSame(['日付', '商品名', '金額', '備考'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(2, $rows);
        $this->assertSame('コーヒー', $rows[0]['商品名']);
        $this->assertSame('紅茶', $rows[1]['商品名']);

        $reader->close();
    }

    public function testMultilineField(): void
    {
        // ExcelでCSV出力した際、改行を含むセルはダブルクォートで囲まれる
        // fgetcsv() はダブルクォート内の改行を正しく認識して1行として扱う
        $reader = new CsvRowReader(encoding: 'UTF-8');
        $reader->open($this->fixturePath . 'multiline.csv');

        $headers = $reader->headers();
        $this->assertSame(['タイトル', '説明', '価格'], $headers);

        $rows = iterator_to_array($reader->rows());
        $this->assertCount(2, $rows);

        // 1行目: ダブルクォート内の改行が保持されている
        $this->assertSame("商品名\n複数行", $rows[0]['タイトル']);
        $this->assertSame('これは説明です', $rows[0]['説明']);
        $this->assertSame('500', $rows[0]['価格']);

        // 2行目: 複数回改行を含む場合も対応
        $this->assertSame("改行\n入り\nテキスト", $rows[1]['タイトル']);
        $this->assertSame('テスト', $rows[1]['説明']);
        $this->assertSame('300', $rows[1]['価格']);

        $reader->close();
    }
}
