ActionModal Helper 利用ガイド
=============================

CakeUtilityプラグインが提供するActionModalは、Bootstrap 4/AdminLTE 3のモーダルを使った確認ダイアログを簡単に実装するためのコンポーネントです。

概要
-------------------------

ActionModalは以下の3つの要素で構成されます。

| 要素                         | 役割                                              |
| ---------------------------- | ------------------------------------------------- |
| `ActionModalHelper`          | トリガーボタンのHTMLを出力(data-* 属性込み)       |
| `action_modal.php` (element) | モーダルのマークアップ(CSRFトークン自動埋め込み)  |
| `action-modal.js`            | data-* 属性を読み取ってモーダルを制御するピュアJS |

セットアップ
-------------------------

### 1. Helper のロード

アプリケーションの `AppView::initialize()` でHelperをロードします。

```php
// src/View/AppView.php
public function initialize(): void
{
    parent::initialize();
    // ...
    $this->loadHelper('CakeUtility.ActionModal');
}
```

### 2. モーダル element と JS の読み込み

モーダルを使うテンプレートでelementとJSを読み込みます。

```php
// テンプレートの末尾などに配置
<?php $this->append('script', $this->Html->script('CakeUtility.action-modal')) ?>
<?= $this->element('CakeUtility.action_modal') ?>
```

レイアウトに一度だけ記述すれば、その配下の全画面でモーダルが利用可能になります。

Helper メソッド
-------------------------

### `button(array $options): string`

汎用のトリガーボタンを出力します。

```php
<?= $this->ActionModal->button([
    'url' => ['action' => 'delete', 1],
    'name' => '商品A',
    'action' => '削除',
]) ?>
```

#### オプション一覧

- `url`
    - 型: `string`/`array`
    - デフォルト: `''`
    - 確定時のPOST先URL。CakePHP配列形式も可
- `name`
    - 型: `string`
    - デフォルト: `''`
    - 対象名。message内の `{name}` を置換
- `action`
    - 型: `string`
    - デフォルト: `'Execute'`
    - 動作名。message内の `{action}` を置換
- `actionText`
    - 型: `string`/`false`
    - デフォルト: `null`(actionと同じ)
    - アクションボタンの文言。`false` で非表示
- `cancelText`
    - 型: `string`/`false`
    - デフォルト: `'Cancel'`
    - キャンセルボタンの文言。`false` で非表示
- `method`
    - 型: `string`
    - デフォルト: `'POST'`
    - 送信メソッド(`'DELETE'` など)
- `bodyUrl`
    - 型: `string`/`array`/`null`
    - デフォルト: `null`
    - HTMXでモーダル本文に読み込むURL。CakePHP配列形式も可
- `title`
    - 型: `string`
    - デフォルト: `'Confirm'`
    - モーダルヘッダーのタイトル
- `message`
    - 型: `string`/`null`
    - デフォルト: `null`(`$name` 有無で2パターンの定型文)
    - 確認メッセージ。`{name}` `{action}` を置換。`null` の場合は自動生成
- `modalClasses`
    - 型: `array`
    - デフォルト: `[]`
    - モーダル各要素のCSSクラス(後述)
- `class`
    - 型: `string`
    - デフォルト: `'btn btn-secondary'`
    - トリガーボタンのCSSクラス
- `target`
    - 型: `string`
    - デフォルト: `'action-modal'`
    - モーダル要素のid

#### `modalClasses` の指定

```php
<?= $this->ActionModal->button([
    'url' => '/test',
    'action' => '編集',
    'modalClasses' => [
        'modal' => 'modal-lg',
        'header' => 'bg-warning',
        'actionBtn' => 'btn btn-warning',
    ],
]) ?>
```

| キー        | デフォルト            | 対象要素             |
| ----------- | --------------------- | -------------------- |
| `modal`     | `'modal-lg'`          | `.modal-dialog`      |
| `header`    | `''`                  | `.modal-header`      |
| `body`      | `'p-3'`               | `.modal-body`        |
| `footer`    | `''`                  | `.modal-footer`      |
| `actionBtn` | `'btn btn-primary'`   | アクション実行ボタン |
| `cancelBtn` | `'btn btn-secondary'` | キャンセルボタン     |

### `deleteButton(array $options): string`

削除操作用のラッパー。以下のデフォルト値が設定されます。

```php
<?= $this->ActionModal->deleteButton([
    'url' => ['action' => 'delete', $entity->id],
    'name' => h($entity->title),
]) ?>
```

#### デフォルト値

| オプション | デフォルト値            |
| ---------- | ----------------------- |
| `action`   | `'Delete'`              |
| `title`    | `'Delete Confirmation'` |
| `class`    | `'btn btn-danger'`      |

Element 変数一覧
-------------------------

`action_modal.php` に直接渡せる変数です(通常はHelper経由で自動設定されます)。

- `$id`
    - 型: `string`
    - デフォルト: `'action-modal'`
    - モーダルの一意識別子
- `$title`
    - 型: `string`
    - デフォルト: `'Confirm'`
    - モーダルヘッダーのタイトル
- `$name`
    - 型: `string`
    - デフォルト: `''`
    - 対象名
- `$action`
    - 型: `string`
    - デフォルト: `'Execute'`
    - 動作名
- `$message`
    - 型: `string`/`null`
    - デフォルト: `null`
    - 確認メッセージ
- `$bodyUrl`
    - 型: `string`/`array`/`null`
    - デフォルト: `null`
    - HTMX読み込みURL
- `$method`
    - 型: `string`
    - デフォルト: `'POST'`
    - 送信メソッド
- `$actionText`
    - 型: `string`/`false`
    - デフォルト: `$action` と同じ
    - アクションボタンの文言
- `$cancelText`
    - 型: `string`/`false`
    - デフォルト: `'Cancel'`
    - キャンセルボタンの文言
- `$modalClasses`
    - 型: `array`
    - デフォルト: `[]`
    - CSSクラス設定

`$message` を省略(`null`)した場合、`$name` の有無に応じて以下の定型文が自動で使用されます。

| `$name`  | 定型文                                      |
| -------- | ------------------------------------------- |
| 空文字   | `Are you sure you want to {action}?`        |
| 指定あり | `Are you sure you want to {action} {name}?` |

`{action}` には `$action` の値、`{name}` には `$name` の値がそれぞれ置き換えられます。
`message` に任意の文字列を指定した場合も、同様に `{action}` と `{name}` が置き換えられます。

data-* 属性(JS)
-------------------------

`action-modal.js` は以下のdata-* 属性を読み取ります。

| 属性                 | 必須           | 説明                                             |
| -------------------- | -------------- | ------------------------------------------------ |
| `data-action-modal`  | トリガー識別用 | この属性があるボタンがモーダルを起動             |
| `data-url`           | 実質必須       | 確定時のPOST先URL                                |
| `data-name`          | 任意           | 対象名(`{name}` 置換用)                          |
| `data-action`        | 任意           | 動作名(`{action}` 置換用)                        |
| `data-action-text`   | 任意           | アクションボタンの文言                           |
| `data-cancel-text`   | 任意           | キャンセルボタンの文言                           |
| `data-method`        | 任意           | 送信メソッド                                     |
| `data-title`         | 任意           | モーダルタイトル                                 |
| `data-body-url`      | 任意           | HTMXで読み込むURL                                |
| `data-message`       | 任意           | 確認メッセージ(テンプレート `{name}` `{action}`) |
| `data-modal-classes` | 任意           | モーダルCSSクラス(JSON)                          |
| `data-target`        | 任意           | モーダル要素のid(デフォルト: `action-modal`)     |

使用例
-------------------------

### シンプル版(削除確認)

```php
// AppView::initialize() 等で Helper をロード済みならテンプレートで即使用可
<?= $this->ActionModal->deleteButton([
    'url' => ['action' => 'delete', $entity->id],
    'name' => h($entity->title),
    'class' => 'btn btn-xs btn-danger',
]) ?>
```

### HTMX版(モーダル本文を動的読み込み)

```php
<?= $this->ActionModal->button([
    'url' => ['action' => 'update', $entity->id],
    'name' => h($entity->title),
    'action' => '更新',
    'bodyUrl' => ['action' => 'editForm', $entity->id],  // 配列でもOK
    'modalClasses' => ['modal' => 'modal-xl'],
]) ?>
```

### actionText/cancelText を false にしてボタンを非表示

```php
<?= $this->ActionModal->button([
    'url' => '/auto-execute',
    'action' => '一括実行',
    'actionText' => false,   // アクションボタン非表示（自動実行など）
    'cancelText' => '閉じる', // キャンセルボタンのみ表示
]) ?>
```

HTMX 連携設定
-------------------------

CakeUtilityプラグインは、HTMXリクエスト時にレイアウトを自動的に無効化する `HtmxLayoutListener` を同梱しています。

### 動作概要

プラグインの `bootstrap()` で `HtmxLayoutListener` をイベント登録すると、`Controller.beforeRender` で `HX-Request` ヘッダーを検出し、自動的に `disableAutoLayout()` を呼び出します。

### 設定

プラグインのデフォルト設定(`config/cake_utility.php`)

```php
'Htmx' => [
    'disableAutoLayout' => true,  // false で無効化
],
```

アプリケーション側で上書きする場合:

```php
// config/cake_utility.php
return [
    'Htmx' => [
        'disableAutoLayout' => false,
    ],
];
```

i18n(多言語対応)
-------------------------

デフォルトの文字列は英語(ソース文字列)で記述されており、`__d('cake_utility', ...)` 経由で翻訳されます。

日本語訳はプラグインに同梱済みです。

```
plugins/CakeUtility/resources/locales/ja_JP/cake_utility.po
```

他の言語を追加する場合は、同様のパターンで .poファイルを作成します。

| 言語   | パス                                            |
| ------ | ----------------------------------------------- |
| 日本語 | `resources/locales/ja_JP/cake_utility.po`(同梱) |
| 追加例 | `resources/locales/fr_FR/cake_utility.po`       |

Element の上書き
-------------------------

プラグインが提供する `action_modal.php` は、アプリケーション側で上書きできます。

モーダルの構造やデザインをカスタマイズしたい場合、以下のパスに同名のファイルを作成すると、プラグインのものより優先して読み込まれます。

```
templates/plugin/CakeUtility/element/action_modal.php
```

プラグインのデフォルト要素をまるごとコピーして修正するか、必要な部分だけ書き直してください。

変数一覧とデフォルト値は本ドキュメントの「Element変数一覧」を参照してください。
