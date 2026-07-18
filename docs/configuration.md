設定カスタマイズガイド
=========================

CakeUtilityプラグインの各機能は、デフォルト設定ファイルとアプリケーション側での上書きによりカスタマイズできます。

設定ファイルの構造
-------------------------

プラグイン側のデフォルト設定は `plugins/CakeUtility/config/cake_utility.php` に定義されています。
設定キーはトップレベルに機能ごとに分割されています。

```php
// plugins/CakeUtility/config/cake_utility.php
return [
    'AuditLog' => [ /* ... */ ],
    'Htmx' => [ /* ... */ ],
    'I18n' => [ /* ... */ ],
    'Scenario' => [ /* ... */ ],
];
```

アプリケーション側での上書き方法
--------------------------------

### 方法1: `config/plugins.php` または `config/bootstrap.php` で設定する

プラグイン読み込み前に `Configure::write()` で設定を上書きします。

```php
// config/plugins.php または config/bootstrap.php
use Cake\Core\Configure;

Configure::write('AuditLog.retentionDays', 180);
Configure::write('Htmx.disableAutoLayout', false);
Configure::write('Scenario.baseDir', 'config/Seeds/my_data');
```

### 方法2: アプリケーション側に設定ファイルを配置する

`config/cake_utility.php` を作成し、プラグイン読み込み前に `Configure::load()` します。

```php
// config/bootstrap.php でプラグイン読み込み前に
Configure::load('cake_utility', 'default');
```

設定項目一覧
-------------------------

### AuditLog - 監査ログ

監査ログの保持期間やCSV出力先を設定します。

| キー                            | 型     | デフォルト                                                  | 説明                                                    |
|---------------------------------|--------|-------------------------------------------------------------|---------------------------------------------------------|
| `AuditLog.retentionDays`        | int    | `90`                                                        | デフォルト保持日数(未指定のカテゴリー/アクションに適用) |
| `AuditLog.retentionByCategory`  | array  | `['auth' => ['login' => 30, 'logout' => 30, 'failed' => 30]]` | カテゴリー+アクションごとの個別保持日数                 |
| `AuditLog.csvExportPath`        | string | `LOGS . 'audit' . DS`                                       | CSV出力先ディレクトリ                                   |
| `AuditLog.csvRetentionDays`     | int    | `365`                                                       | CSVファイルのデフォルト保持日数                         |
| `AuditLog.csvRetentionByCategory` | array  | `['auth' => ['__default__' => 180]]`                        | カテゴリーごとのCSV保持日数                             |

#### 参照しているクラス

- `AuditLogPurgeService` - 保持期間超過レコードのパージ、CSV出力
- `AuditLogWriter` - 保持期間の参照

### Htmx - HTMX連携

HTMXリクエスト時のレイアウト制御を設定します。

| キー                   | 型   | デフォルト | 説明                                         |
|------------------------|------|------------|----------------------------------------------|
| `Htmx.disableAutoLayout` | bool | `true`     | HTMXリクエスト時にレイアウトを自動無効化する |

#### 参照しているクラス

- `CakeUtilityPlugin::bootstrap()` - 自動無効化のON/OFFを判断

### I18n - 多言語対応(将来拡張用)

ロケール自動切り替えのデフォルト値を設定します。
現在の `LocaleMiddleware` はCakePHP標準の `App.paths.locales`/`App.defaultLocale` を参照するため、このセクションは将来の拡張用の定義として保持しています。

| キー                  | 型     | デフォルト         | 説明                     |
|-----------------------|--------|--------------------|--------------------------|
| `I18n.defaultLocale`  | string | `'ja_JP'`          | デフォルトロケール       |
| `I18n.supportedLocales` | array  | `['ja_JP', 'en_US']` | サポートするロケール一覧 |

### Scenario - シナリオデータ管理

`ScenarioLoader` および `bin/cake scenario` コマンドのベースディレクトリを設定します。

| キー             | 型     | デフォルト          | 説明                                     |
|------------------|--------|---------------------|------------------------------------------|
| `Scenario.baseDir` | string | `'config/Seeds/data'` | シナリオYAMLファイルのベースディレクトリ |

#### 参照しているクラス

- `ScenarioLoader` - `$basePath` 未指定時のデフォルト値として参照
- `ScenarioCommand` - `--base-dir` オプションのデフォルト値として参照

> **Note**: `ScenarioLoader` をコンストラクタで明示的に `$basePath` を指定して生成した場合、設定値よりもその値が優先されます。設定値は `$basePath` を省略した場合のフォールバックとして使用されます。

アプリケーション側設定例
-------------------------

```php
// config/plugins.php
use Cake\Core\Configure;

// 監査ログ: authカテゴリの保持期間を延長、auth関連のCSV保持期間も延長
Configure::write('AuditLog.retentionByCategory', [
    'auth' => [
        'login' => 60,
        'logout' => 60,
        'failed' => 90,
    ],
]);

// HTMX: レイアウト自動無効化を無効にする（アプリ側で制御する場合）
Configure::write('Htmx.disableAutoLayout', false);

// シナリオ: カスタムディレクトリを指定
Configure::write('Scenario.baseDir', 'config/Seeds/my_project');
```
