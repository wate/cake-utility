CakeUtility plugin for CakePHP
==============================

CakePHP 5向けユーティリティプラグインです。監査ログ、インポートワークフロー、ActionModal、YAMLローダー、シナリオローダー、ロケール自動切り替えの各機能を提供します。

インストール
-------------------------

Packagistに公開していないため、GitHubリポジトリを直接指定してインストールします。

### 1. `composer.json` にリポジトリを追加

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/wate/cake-utility.git"
        }
    ],
    "require": {
        "wate/cake-utility": "dev-main"
    }
}
```

### 2. Composer でインストール

```bash
composer require wate/cake-utility:dev-main
```

### 3. プラグインを有効化

`config/plugins.php` に追加:

```php
CakeUtility::class,
```

Excel(XLSX)のインポートに対応する場合(オプション):

```bash
composer require phpoffice/phpspreadsheet
```

機能一覧
-------------------------

### Import Workflow(インポートワークフロー)

CSV/Excelファイルの一括インポートを共通化するワークフローです。プレビュー、バリデーション、upsertに対応しています。

- CSVパース: Shift_JISの5C問題対策、エンコーディング自動検出
- Excel(XLSX)対応: PhpSpreadsheetをオプション依存として同梱
- パイプライン: rowFilter → lookup(FK一括解決) → columnMap → fixed → beforeMarshal → validate → beforeSave → afterSave
- upsert、バッチ分割保存、行フィルター
- `preview()` + `execute()` の分割パターン
- 結果表示用の共有Viewエレメント

[詳細 →](docs/import_workflow.md)

### Audit Log(監査ログ)

変更履歴を自動記録する監査ログ機能です。BehaviorによるModel層の自動記録と、ComponentによるController層からの明示的記録の2経路を備えています。

- Behavior: 作成/更新/削除を自動検知
- Component: ログイン/ログアウト等の明示的記録
- IPアドレス・UserAgentの自動取得
- サニタイズコールバック(PIIマスキング)
- 保持期間ベースの自動パージ + CSVアーカイブ
- カテゴリー別保持期間設定
- CLIパージコマンド(--force対応)

[詳細 →](docs/audit_log.md)

### ActionModal(確認ダイアログ)

Bootstrap 4/AdminLTE 3のモーダルを使った確認ダイアログです。

- Helper: data-* 属性付きトリガーボタンを出力
- Element: CSRFトークンを自動埋め込みするモーダルマークアップ
- JS: ピュアJS + HTMXでモーダル制御
- i18n対応(英語ソース + 日本語.po)
- HTMXによる動的本文読み込み対応
- 操作種別ごとにCSSクラスをカスタマイズ可能

[詳細 →](docs/action_modal.md)

### YAML Loader(YAMLローダー)

YAML形式のテストデータやシードデータをDB投入可能な形式に変換します。

- `ref:` プレフィックスによるFK参照解決
- `@now`/`@today` などの動的日時指定
- スキーマベースの型変換(boolean/JSON/integer/datetime)
- インラインマップ・コールバック形式のルックアップ対応

[詳細 →](docs/yaml_loader.md)

### Scenario Loader & CLI(シナリオローダー)

YAMLシナリオファイルからテストデータを冪等にデータベースに投入・削除します。

- 依存関係グラフの構築とトポロジカルソート
- `_keys` ベースの冪等upsert(更新or新規作成)
- ファイルを跨いだ `_ref` 参照マップ管理
- テーブル単位のトランザクション制御
- CLIコマンド: `bin/cake scenario load/clear`(`--base-dir` オプション対応)
- デフォルトベースディレクトリは `Configure::write('Scenario.baseDir')` で設定可能

[詳細 →](docs/scenario_loader.md)

### Locale Middleware(ロケール自動切り替え)

ブラウザの言語設定に基づいてアプリケーションのロケールを自動的に切り替えます。

優先順位:

1. URLパラメータ(`?lang=en_US`)
2. Cookie(前回の選択を保存)
3. ブラウザのAccept-Languageヘッダー(RFC 9110準拠)
4. アプリケーションのデフォルトロケール(フォールバック)

[詳細 →](docs/locale_middleware.md)

### HTMX Layout Listener(HTMXレイアウト自動無効化)

HTMXリクエスト時にコントローラのレイアウトレンダリングを自動的に無効化するリスナーです。
`HX-Request` ヘッダーを検出し、部分更新に不要なレイアウトHTMLがレスポンスに含まれないように制御します。
プラグインのブートストラップ時に自動登録され、設定で有効/無効を切り替えられます。

[詳細 →](docs/htmx_layout_listener.md)

### 設定

各機能の設定は `config/cake_utility.php` で管理します。

- AuditLog: 保持期間、CSV出力先、カテゴリー別設定
- HTMX: レイアウト自動無効化のON/OFF
- ScenarioLoader: デフォルトベースディレクトリ

[詳細 →](docs/configuration.md)

開発
-------------------------

```bash
# プラグイン単体のテスト実行
cd plugins/CakeUtility
composer test
```
