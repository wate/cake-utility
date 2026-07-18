ImportWorkflow - クイックスタート
=================================

`CakeUtility\Import\ImportWorkflow` は、CSVファイルのアップロードからデータベースへの一括登録までを共通化するワークフローです。
アプリ側で対象モデルと列マッピングを与えるだけでインポート画面が成立します。

何ができるのか
-------------------------

- CSVの各行をパースし、バリデーションをかけてデータベースに登録します
- Shift_JISのCSVも自動変換して処理します(5C問題対応)
- 外部キーは `lookup` 設定でバッチ解決できるため、N+1問題が発生しません
- Excel(XLSX)にも対応しています(要: `composer require phpoffice/phpspreadsheet`)
- 1画面完結の即時登録が基本ですが、`preview()` + `execute()` の分割で確認画面パターンにも対応可能です

5分で始める
-------------------------

### 1. アップロード用Formクラスを作成する

```php
// src/Form/ImportForm.php
namespace App\Form;

use Cake\Form\Form;
use Cake\Form\Schema;
use Cake\Validation\Validator;

class ImportForm extends Form
{
    protected function _buildSchema(Schema $schema): Schema
    {
        return $schema->addField('import_file', ['type' => 'file']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        return $validator
            ->notEmptyFile('import_file', __('ファイルを選択してください'))
            ->uploadedFile('import_file', [
                'types' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
                'maxSize' => 5242880,
            ], __('CSVファイルのみアップロード可能です（最大5MB）'));
    }
}
```

### 2. Controllerでインポート処理を実装する

```php
// src/Controller/Admin/HearingsController.php
use CakeUtility\Import\ImportWorkflow;
use CakeUtility\Import\CsvRowReader;
use App\Form\ImportForm;

public function import()
{
    $result = null;
    $form = new ImportForm();

    if ($this->request->is('post')) {
        if ($form->execute($this->request->getData())) {
            $file = $this->request->getData('import_file');
            $reader = new CsvRowReader();
            $importWorkflow = new ImportWorkflow(
                reader: $reader,
                table: $this->Hearings,
                options: [
                    'columnMap' => [
                        'タイトル' => 'title',
                        '説明' => 'description',
                    ],
                    'fixed' => [
                        'user_id' => $this->Authentication->getIdentity()->id,
                    ],
                ],
            );
            $result = $importWorkflow->import(
                $file->getStream()->getMetadata('uri')
            );
        }
    }

    $this->set(compact('form', 'result'));
}
```

### 3. テンプレートで結果を表示する

```php
// templates/Admin/Hearings/import.php
<?= $this->Form->create($form, ['type' => 'file']) ?>
    <?= $this->Form->control('import_file', ['type' => 'file', 'label' => 'CSVファイル']) ?>
    <?= $this->Form->button('インポート実行', ['class' => 'btn btn-primary']) ?>
<?= $this->Form->end() ?>

<?php if (isset($result)): ?>
    <?= $this->element('CakeUtility.import_result', [
        'result' => $result,
        'columns' => ['title', 'description'],
    ]) ?>
<?php endif; ?>
```

中級者向けカスタマイズ
-------------------------

### 外部キーを解決する(lookup)

CSVに部署名のような表示名があり、それを `department_id` として保存したい場合は `lookup` を使います。
内部的に全行の値を収集→1回のSELECT→メモリキャッシュするので、N+1問題は発生しません。

```php
'lookup' => [
    '部署名' => [
        'table' => 'Departments',
        'from' => 'name',
        'to' => 'id',
        'default' => null, // 見つからなかった場合の値
    ],
],
```

### バリデーション前に値を加工する(beforeMarshal)

日付のフォーマット統一など、バリデーション前に値を加工したい場合に使います。

```php
'beforeMarshal' => function (array $row) {
    // 例: 2026/1/1 → 2026-01-01 に統一
    if (!empty($row['date'])) {
        $row['date'] = date('Y-m-d', strtotime($row['date']));
    }
    return $row;
},
```

### 保存前にエンティティを調整する(beforeSave)

```php
'beforeSave' => function (EntityInterface $entity) {
    // 保存前の最終調整
    return $entity;
},
```

### 確認画面パターンに対応する

`import()` の代わりに `preview()` と `execute()` を分離して使います。

```php
// 1度目のリクエスト: プレビュー表示
$preview = $importWorkflow->preview($filePath);
// $preview をセッションに保存 or 画面表示

// 2度目のリクエスト: 確認後の登録
$result = $importWorkflow->execute($entities);
```

設定リファレンス
-------------------------

### ImportWorkflow のオプション一覧

| オプション    | 型       | デフォルト         | 説明                                 |
|---------------|----------|--------------------|--------------------------------------|
| `columnMap`   | array    | `[]`(同名として扱う) | CSV列名 → モデルカラム名のマッピング |
| `fixed`       | array    | `[]`               | 全行に一律でセットする値             |
| `lookup`      | array    | null               | null                                 | FKバッチ解決の設定                                      |
| `beforeMarshal` | callable | null               | null                                 | バリデーション前の加工                                  |
| `beforeSave`  | callable | null               | null                                 | 保存前のエンティティ加工                                |
| `validate`    | string   | Validator          | `'default'`                          | バリデーションルールセット名またはValidatorオブジェクト |

### RowReader のオプション一覧

| クラス               | オプション | 型     | デフォルト | 説明                       |
|----------------------|------------|--------|------------|----------------------------|
| `CsvRowReader`       | `encoding` | string | `'sjis'`   | CSVの文字コード(iconv形式) |
| `CsvRowReader`       | `delimiter` | string | `','`      | 区切り文字                 |
| `CsvRowReader`       | `enclosure` | string | `'"'`      | 囲み文字                   |
| `SpreadsheetRowReader` | `sheetName` | string | null       | null(最初のシート)         | 読み込むシート名 |

### look up の設定項目

| キー    | 必須 | 説明                                          |
|---------|------|-----------------------------------------------|
| `table` | ○    | 参照先のテーブル名またはクラス名              |
| `from`  | ○    | 検索に使うカラム名(CSV側の値と一致するカラム) |
| `to`    | ○    | 取得するカラム名(FKに入れる値)                |
| `default` | -    | 見つからなかった場合の値(省略時は null)       |

### 戻り値

- `preview()`: `PreviewResult` - バリデーション済みエンティティとエラー情報
- `execute()`: `ImportResult` - 保存成功件数・保存後エンティティ・エラー情報
- `import()`: `ImportResult` - preview + executeのショートカット
