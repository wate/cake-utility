<?php

declare(strict_types=1);

namespace CakeUtility\Import;

use function Cake\I18n\__d;

/**
 * CsvRowReader
 *
 * CSV実装（本体同梱）。
 * 5C問題(Shift_JISのダメ文字)対策として、stream_filter_append + iconv で
 * 読み込み時にUTF-8に変換しながらパースする。
 */
class CsvRowReader implements RowReaderInterface
{
    private string $encoding;

    private string $delimiter;

    private string $enclosure;

    private string $escape;

    /** @var bool|int ヘッダー行数。true=1行, false=なし, int=指定行数 */
    private bool|int $headerRows;

    /** @var resource|null */
    private $handle = null;

    /** @var array<string>|null */
    private ?array $headerRow = null;

    /** @var array<int, array{row: int, message: string, data: array<string, mixed>}> */
    private array $errors = [];

    public function __construct(
        string $encoding = 'auto',
        bool|int $headerRows = true,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ) {
        $this->encoding = $encoding;
        $this->headerRows = $headerRows === true ? 1 : ($headerRows === false ? 1 : max(1, (int)$headerRows));
        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape = $escape;
    }

    public function open(string $filePath): void
    {
        $this->errors = [];
        $this->headerRow = null;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException(
                __d('cake_utility', 'Could not open file: {0}', $filePath)
            );
        }

        // エンコーディング自動検出（auto の場合）
        $encoding = $this->encoding;
        $upperEncoding = strtoupper($encoding);
        if ($upperEncoding === 'AUTO' || $encoding === '') {
            $encoding = $this->detectEncoding($filePath);
        }

        $this->handle = fopen($filePath, 'r');
        if ($this->handle === false) {
            throw new \RuntimeException(
                __d('cake_utility', 'Could not open file: {0}', $filePath)
            );
        }

        // エンコーディング変換フィルタを適用（UTF-8以外の場合のみ）
        if ($encoding !== null && strtoupper($encoding) !== 'UTF-8') {
            $filterName = 'convert.iconv.' . $encoding . '/UTF-8';
            $filter = stream_filter_append($this->handle, $filterName, STREAM_FILTER_READ);
            if ($filter === false) {
                fclose($this->handle);
                $this->handle = null;
                throw new \RuntimeException(
                    __d('cake_utility', 'Could not create encoding filter: {0}', $filterName)
                );
            }
        }

        // ヘッダー行を読み込む（複数行対応）
        $headerCount = $this->headerRows;
        $headerRowsLines = [];
        for ($i = 0; $i < $headerCount; $i++) {
            $line = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape);
            if ($line === false || $line === null || (count($line) === 1 && $line[0] === null)) {
                fclose($this->handle);
                $this->handle = null;
                throw new \RuntimeException(
                    __d('cake_utility', 'CSV file is empty')
                );
            }
            $headerRowsLines[] = $line;
        }

        // カラム名はヘッダーの最終行を採用
        $columnNames = end($headerRowsLines);

        // BOM除去（UTF-8 BOM: \xEF\xBB\xBF）
        if (count($columnNames) > 0) {
            $columnNames[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columnNames[0]);
        }

        $this->headerRow = $columnNames;
    }

    /**
     * ファイルの先頭を読み込み、文字コードを自動検出する。
     *
     * @param string $filePath ファイルパス
     * @return string|null 検出されたエンコーディング名（iconv形式）。UTF-8の場合は null
     */
    private function detectEncoding(string $filePath): ?string
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return null;
        }

        $chunk = fread($handle, 8192);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        // BOMチェック（UTF-8 BOM）
        if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        // UTF-16 BOM
        if (str_starts_with($chunk, "\xFE\xFF") || str_starts_with($chunk, "\xFF\xFE")) {
            return 'UTF-16';
        }

        // mb_detect_encoding で判定
        $detected = mb_detect_encoding($chunk, ['SJIS-win', 'UTF-8', 'eucJP-win', 'JIS', 'ASCII'], true);
        if ($detected === false) {
            // 検出できなかった場合は SJIS-win とみなす（日本の業務システムのデフォルト）
            return 'SJIS-win';
        }

        return $detected;
    }

    public function headers(): array
    {
        if ($this->headerRow === null) {
            throw new \RuntimeException(
                __d('cake_utility', 'File is not open. Call open() first')
            );
        }

        return $this->headerRow;
    }

    public function rows(): iterable
    {
        if ($this->handle === null) {
            throw new \RuntimeException(
                __d('cake_utility', 'File is not open. Call open() first')
            );
        }

        $headers = $this->headers();
        $headerCount = count($headers);
        $lineNumber = 1; // ヘッダー行は既に読んだので1始まり

        while (($row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, $this->escape)) !== false) {
            $lineNumber++;

            // 空行スキップ
            if (count($row) === 1 && ($row[0] === null || $row[0] === '')) {
                continue;
            }

            // カラム数が合わない場合はエラー
            if (count($row) !== $headerCount) {
                $this->errors[] = [
                    'row' => $lineNumber,
                    'message' => __d('cake_utility', 'Column count mismatch (expected: {0}, actual: {1})', $headerCount, count($row)),
                    'data' => array_combine(
                        $headers,
                        array_pad(array_slice($row, 0, $headerCount), $headerCount, '')
                    ),
                ];
                continue;
            }

            yield array_combine($headers, $row);
        }
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->headerRow = null;
        $this->errors = [];
    }
}
