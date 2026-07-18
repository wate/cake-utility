<?php

declare(strict_types=1);

namespace CakeUtility\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

use function Cake\I18n\__d;

/**
 * SpreadsheetRowReader
 *
 * Excel(XLSX)実装。
 * 同梱するが `phpoffice/phpspreadsheet` は composer.json の require には含めない。
 * require-dev に含めて開発時のみインストールされ、アプリへの依存伝搬は防ぐ。
 * 実行時に class_exists() でPhpSpreadsheetの有無を確認する。
 */
class SpreadsheetRowReader implements RowReaderInterface
{
    /** @var string|null */
    private ?string $sheetName;

    /** @var bool|int ヘッダー行数。true=1行, false=なし, int=指定行数 */
    private bool|int $headerRows;

    /** @var Worksheet|null */
    private ?Worksheet $sheet = null;

    /** @var array<string>|null */
    private ?array $headerRow = null;

    /** @var array<int, array{row: int, message: string, data: array<string, mixed>}> */
    private array $errors = [];

    /**
     * @param string|null $sheetName 読み込むシート名（nullで最初のシート）
     * @param bool|int $headerRows ヘッダー行数。true=1行, false=なし, 数値=指定行数（カラム名は最終行を採用）
     * @throws \RuntimeException PhpSpreadsheetがインストールされていない場合
     */
    public function __construct(
        ?string $sheetName = null,
        bool|int $headerRows = true,
    ) {
        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException(
                __d('cake_utility', 'PhpSpreadsheet is required. Run: composer require phpoffice/phpspreadsheet')
            );
        }

        $this->sheetName = $sheetName;
        $this->headerRows = $headerRows;
    }

    /**
     * @inheritDoc
     */
    public function open(string $filePath): void
    {
        $this->errors = [];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException(
                __d('cake_utility', 'Could not open file: {0}', $filePath)
            );
        }

        $spreadsheet = IOFactory::load($filePath);

        if ($this->sheetName !== null) {
            $this->sheet = $spreadsheet->getSheetByName($this->sheetName);
            if ($this->sheet === null) {
                throw new \RuntimeException(
                    __d('cake_utility', 'Sheet not found: {0}', $this->sheetName)
                );
            }
        } else {
            $this->sheet = $spreadsheet->getActiveSheet();
        }

        // ヘッダー行の読み込み
        if ($this->headerRows !== false) {
            $headerCount = $this->headerRows === true ? 1 : (int)$this->headerRows;
            $lastDataColumn = $this->getLastColumn();
            $headerRows = [];
            for ($i = 1; $i <= $headerCount; $i++) {
                $row = $this->sheet->rangeToArray('A' . $i . ':' . $lastDataColumn . $i, null, true, false)[0] ?? [];
                $headerRows[] = $row;
            }
            // カラム名はヘッダーの最終行を採用
            $this->headerRow = end($headerRows);
        } else {
            // ヘッダーなしの場合はカラム名を生成
            $highestColumn = $this->sheet->getHighestColumn();
            $colIndex = 'A';
            $headers = [];
            while (strlen($colIndex) <= strlen($highestColumn)) {
                $headers[] = $colIndex;
                if ($colIndex === $highestColumn) {
                    break;
                }
                $colIndex++;
            }
            $this->headerRow = $headers;
        }
    }

    /**
     * @inheritDoc
     */
    public function headers(): array
    {
        if ($this->headerRow === null) {
            throw new \RuntimeException(
                __d('cake_utility', 'File is not open. Call open() first')
            );
        }

        return $this->headerRow;
    }

    /**
     * @inheritDoc
     */
    public function rows(): iterable
    {
        if ($this->sheet === null || $this->headerRow === null) {
            throw new \RuntimeException(
                __d('cake_utility', 'File is not open. Call open() first')
            );
        }

        $headers = $this->headerRow;
        $headerCount = count($headers);
        $highestRow = $this->sheet->getHighestRow();
        $lastColumn = $this->getLastColumn();

        // データ行の開始行
        $startRow = 1;
        if ($this->headerRows !== false) {
            $startRow = ($this->headerRows === true ? 1 : (int)$this->headerRows) + 1;
        }

        for ($rowIndex = $startRow; $rowIndex <= $highestRow; $rowIndex++) {
            $rowData = $this->sheet->rangeToArray(
                'A' . $rowIndex . ':' . $lastColumn . $rowIndex,
                null,
                true,
                false
            )[0] ?? [];

            // 空行スキップ
            if (empty(array_filter($rowData, fn($val) => $val !== null && $val !== ''))) {
                continue;
            }

            // カラム数が合わない場合はエラー
            if (count($rowData) !== $headerCount) {
                $this->errors[] = [
                    'row' => $rowIndex,
                    'message' => __d('cake_utility', 'Column count mismatch (expected: {0}, actual: {1})', $headerCount, count($rowData)),
                    'data' => array_combine(
                        $headers,
                        array_pad(array_slice($rowData, 0, $headerCount), $headerCount, '')
                    ),
                ];
                continue;
            }

            yield array_combine($headers, $rowData);
        }
    }

    /**
     * @inheritDoc
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->sheet = null;
        $this->headerRow = null;
        $this->errors = [];
    }

    /**
     * シートの最終カラム文字列を取得する。
     *
     * @return string
     */
    private function getLastColumn(): string
    {
        if ($this->sheet === null) {
            return 'Z';
        }

        return $this->sheet->getHighestColumn();
    }
}
