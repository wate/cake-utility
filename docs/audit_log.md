監査ログ(AuditLog) 仕様
=========================

`CakeUtility\Audit` は、アプリケーション内の変更履歴を自動記録する監査ログ機能を提供します。
BehaviorによるModel層の自動記録と、ComponentによるController層からの明示的記録の2経路を備え、
保存処理はAuditLogWriterに一元化されています。

基本コンセプト
-------------------------

監査ログは追記専用(INSERTのみ)で、更新は行いません。
無制限の蓄積によるテーブル肥大化を防ぐため、保持期間超過レコードはCSV出力後に自動削除(パージ)されます。

### アーキテクチャ

```
Controller層: AuditLogComponent ──┐
                                   ├──→ AuditLogWriter → audit_logs テーブル
Model層:      AuditLogBehavior ────┘

パージ:       AuditLogPurgeService    → CSV出力 + DB削除
              AuditLogPurgeCommand    → CLIからの一括パージ
```

- AuditLogWriter: テーブルへのINSERTのみを担当する中核クラス
- AuditLogBehavior: ModelのafterSave/afterDeleteフックで自動記録
- AuditLogComponent: Controllerで明示的に `saveLog()` を呼び出して記録
- AuditLogPurgeService: パージ処理(CSV出力→DB削除→古いCSV削除)
- AuditLogPurgeCommand: CLIやcronからパージを実行するCommand

テーブル定義
-------------------------

```sql
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL COMMENT '実行ユーザー(null=未認証)',
    category VARCHAR(50) NOT NULL COMMENT '分類(大枠。Behavior時はモデル名、Component時は指定値)',
    action VARCHAR(50) NOT NULL COMMENT 'アクション種別(詳細。create/update/delete/login/logout等)',
    target_id VARCHAR(50) NULL COMMENT '対象レコードのID',
    ip_address VARCHAR(45) NULL COMMENT 'リクエスト元IP',
    user_agent VARCHAR(255) NULL COMMENT 'UserAgent',
    context JSON NULL COMMENT 'コンテキスト情報(Behavior時: before/after、Component時: その他任意情報)',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_category_action (category, action),
    INDEX idx_audit_logs_category_target (category, target_id),
    INDEX idx_audit_logs_user_created (user_id, created),
    INDEX idx_audit_logs_ip_ua (ip_address, user_agent(100))
);
```

### インストール

```bash
# プラグインのマイグレーションを実行
bin/cake migrations migrate --plugin CakeUtility
```

AuditLogBehavior
-------------------------

Modelの作成/更新/削除を自動検知して監査ログを記録します。

### アタッチ方法

```php
// src/Model/Table/ArticlesTable.php
$this->addBehavior('CakeUtility.AuditLog');
```

### 設定オプション

```php
$this->addBehavior('CakeUtility.AuditLog', [
    'excludedFields' => ['created', 'modified', 'password'],  // 記録対象外カラム
    'connectionName' => 'default',                           // 使用するDB接続名
]);
```

### 記録内容

| イベント | category | action | context                                 |
| -------- | -------- | ------ | --------------------------------------- |
| 新規作成 | モデル名 | create | `{before: {}, after: {全カラム}}`       |
| 更新     | モデル名 | update | `{before: {元の値}, after: {新しい値}}` |
| 削除     | モデル名 | delete | `{before: {削除前の値}, after: {}}`     |

### サニタイズコールバック

テーブルに `auditLogSanitize()` メソッドを定義すると、記録直前に呼び出され、
コンテキスト内容を書き換えられます。個人情報のマスキングなどに利用します。

```php
// src/Model/Table/UsersTable.php
public function auditLogSanitize(string $action, array $before, array $after): array
{
    $sensitiveFields = ['password', 'email'];
    foreach ($sensitiveFields as $field) {
        if (isset($before[$field])) {
            $before[$field] = '[REDACTED]';
        }
        if (isset($after[$field])) {
            $after[$field] = '[REDACTED]';
        }
    }
    return ['before' => $before, 'after' => $after];
}
```

AuditLogComponent
-------------------------

Controllerから明示的に監査ログを記録します。主にModel層を経由しないイベントで使用します。

### ロード方法

```php
// src/Controller/AppController.php
$this->loadComponent('CakeUtility.AuditLog');
```

### 記録例

```php
// ログイン記録（記録後にパージも実行）
$this->AuditLog->saveLog('auth', 'login', context: ['login_id' => $id], purge: true);

// ログアウト記録
$this->AuditLog->saveLog('auth', 'logout', context: ['login_id' => $id]);

// パスワード変更
$this->AuditLog->saveLog('auth', 'password_change', context: ['user_id' => $id]);

// エクスポート
$this->AuditLog->saveLog('export', 'download', context: ['file' => 'report.csv']);
```

### saveLog() のシグネチャ

```php
saveLog(string $category, string $action, array $context = [], bool $purge = false): EntityInterface|false
```

- `$category`: 分類(例: `'auth'`, `'system'`, `'export'`, `'admin'`)
- `$action`: アクション種別(例: `'login'`, `'logout'`, `'download'`)
- `$context`: 任意の付加情報(ip_address/user_agentは自動取得されるため不要)
- `$purge`: `true` を渡すと記録後にパージ処理を実行

> メソッド名が `saveLog` なのは、基底クラス `Cake\Controller\Component` が
> すでに PSR-3 準拠の `log(string $message, int $level, array $context): bool` を
> 持っており、命名が衝突するためです。

データ保持とパージ
-------------------------

### 設定例

```php
// config/cake_utility.php
return [
    'AuditLog' => [
        'retentionDays' => 90,                          // デフォルト保持日数
        'retentionByCategory' => [                       // カテゴリ＋アクションごとの個別設定
            'auth' => [
                'login' => 30,
                'logout' => 30,
                'failed' => 30,
            ],
        ],
        'csvExportPath' => LOGS . 'audit' . DS,          // CSV出力先
        'csvRetentionDays' => 365,                       // CSVファイルの保持日数
        'csvRetentionByCategory' => [
            'auth' => ['__default__' => 180],
        ],
    ],
];
```

### パージの仕組み

1. ログインパージ(推奨): `saveLog('auth', 'login', purge: true)` で記録時に自動パージ
2. CLIパージ: `bin/cake audit_log_purge` で一括パージ(cronにも設定可能)

パージの流れ:

```
保持期間超過レコードをSELECT
    ↓
CSVファイルに出力 (audit_logs_{category}_{YYYY-MM-DD}.csv)
    ↓
DBからレコードを削除
    ↓
保持期間超過の古いCSVファイルを削除
```

AuditLogPurgeCommand
-------------------------

CLIから保持期間超過レコードをパージするためのコマンドです。

```bash
# 通常実行
bin/cake audit_log_purge

# test接続で実行（テスト時）
bin/cake audit_log_purge --connection test

# オプション一覧
bin/cake audit_log_purge --help
```

| オプション            | 説明                     | デフォルト |
| --------------------- | ------------------------ | ---------- |
| `--force` / `-f`      | 確認プロンプトをスキップ | なし       |
| `--connection` / `-c` | 使用するDB接続名         | default    |
| `--help`              | ヘルプ表示               |            |

補足
-------------------------

### プラグイン単体テスト

```bash
cd plugins/CakeUtility
vendor/bin/phpunit
```

### 動作確認のポイント

- Behaviorアタッチ後、save/deleteで `audit_logs` にレコードが挿入される
- Componentの `saveLog()` でIP/UAが自動取得される
- `purge: true` で保持期間超過レコードがCSV出力+DB削除される
- サニタイズコールバックでパスワードなどがマスキングされる

今後の検討
-------------------------

### AuditLogsTable クラス

現状、`audit_logs` テーブル用の専用Tableクラス(`AuditLogsTable`)は作成していません。
CakePHPのテーブルロケーターは、専用クラスが存在しない場合でも自動的に `\Cake\ORM\Table`
インスタンスを生成するため、INSERT程度の操作では問題なく動作します。

以下の要件が出てきた時点で `src/Model/Table/AuditLogsTable.php` を作成すれば十分です。

- カスタムファインダー(`findByCategory()` など)を定義したい
- 他のテーブルとアソシエーションを組みたい
- テーブル単位のバリデーションを追加したい

### 監査ログ管理画面

現在はログの保存(Push型)のみで、蓄積されたログを確認・検索・操作する管理画面は提供していません。
今後の拡張として、以下のような管理画面の実装が考えられます。

- ログ一覧表示(カテゴリー・アクション・日時でのフィルタリング)
- ログ詳細表示(context JSONの展開)
- 手動パージ実行トリガー
- CSVダウンロード(現在のパージ時CSV出力とは別に、任意の検索結果をCSV出力)

管理画面を実装する場合、前述の `AuditLogsTable` も併せて必要になります。
カスタムファインダーによる検索条件の組み立てや、アソシエーションによるユーザー名解決など、
テーブルクラスにロジックを集約する設計が適切だからです。
