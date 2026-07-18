<?php

declare(strict_types=1);

namespace CakeUtility\Import;

/**
 * RowReaderInterface
 *
 * インポートするファイルのフォーマット抽象。
 * CSVやExcelなど、異なるフォーマットのリーダーはこのインターフェースを実装する。
 */
interface RowReaderInterface
{
    /**
     * ファイルを開く。
     *
     * @param string $filePath ファイルパス
     * @return void
     * @throws \RuntimeException ファイルが開けない場合
     */
    public function open(string $filePath): void;

    /**
     * ヘッダー行を取得する。
     *
     * @return array<string> カラム名の配列
     */
    public function headers(): array;

    /**
     * データ行を反復取得する。
     *
     * @return iterable<array<string, mixed>> 連想配列の行データ
     */
    public function rows(): iterable;

    /**
     * パースエラーを取得する。
     *
     * @return array<int, array{row: int, message: string, data: array<string, mixed>}> エラー行の情報
     */
    public function errors(): array;

    /**
     * リソースを解放する。
     *
     * @return void
     */
    public function close(): void;
}
