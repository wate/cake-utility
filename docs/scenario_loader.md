ScenarioLoader 仕様
=========================

`CakeUtility\Yaml\ScenarioLoader` は、`Yaml\Loader` が変換したデータをCakePHPのORMを通じてデータベースに安全かつ冪等に投入するための管理クラスです。

基本コンセプト
-------------------------

`Yaml\Loader` が「YAMLというテキストを、正しい型のPHP配列に変換する」という純粋な変換機能を担うのに対し、`ScenarioLoader` は「変換されたデータを、どの順番でどう保存し、IDをどう管理するか」という実行フロー(オーケストレーション)を担います。

主要責務
-------------------------

1. **依存関係解決による正しい順序付け**
    - YAMLファイル内の `ref:` 参照を自動抽出し、トポロジカルソートによって依存順に並べ替えます。
    - これにより、参照先のデータが参照元のデータより先に読み込まれることが保証されます。
2. **シナリオの実行管理**
    - 指定されたディレクトリ配下のYAMLファイルを走査し、依存関係を尊重した順序でデータを投入します。
    - テーブル名の自動推論(ファイル名からCamelCaseに変換)により、明示的な指定が不要です。
3. **IDライフサイクルの管理 (refMap)**
    - `_ref` ラベルと、DB保存後に確定した実IDの紐付けを管理します。
    - これにより、後続のレコードが `ref:label` 形式で前のレコードを参照することを可能にします。
4. **冪等性の確保 (Upsert)**
    - `_keys` 定義に基づき、既存レコードの有無を判定します。
    - レコードが存在すれば「更新」、存在しなければ「新規挿入」を行うことで、何度実行しても同一の状態になることを保証します。
5. **トランザクション制御**
    - シナリオ単位(またはファイル単位)でのトランザクション管理を行い、不完全なデータ投入を防ぎます。

詳細仕様
-------------------------

### 1. 投入フロー (Execution Flow)

1. ファイル走査:
    - 指定されたベースディレクトリおよびシナリオ名に基づき、対象のYAMLファイルを特定します。
    - 見つかったファイルはアルファベット順に一度収集されます。
2. 依存関係の解決と順序付け:
    - 各YAMLファイルから `ref:` 参照を自動抽出します。
    - 抽出した参照からトポロジカルグラフを構築します。
    - Kahn'sアルゴリズムによるトポロジカルソートで、ファイルの依存順を決定します。
    - 例: `users.yml` が `groups.yml` を参照する場合、`groups.yml` が必ず先に処理されます。
3. データ変換と投入:
    - 各レコードに対し、以下の手順で処理します。
    - 参照解決: `Yaml\Loader::resolve()` を呼び出し、`refMap` を用いて `ref:label` を実IDに置換し、同時にスキーマに基づいた型キャストを行います。
    - レコード特定: `_keys` に指定されたカラムと値を用いてDBを検索し、対象となるエンティティを取得します。
    - 永続化: `Table::save()` を実行し、DBに保存します。
    - ID確定: 保存後の実IDを取得し、`_ref` ラベルと共に `refMap` に登録します。
4. 完了: 全レコードの投入が完了し、トランザクションをコミットします。

### 2. Upsert ロジック (`_keys`)

データ投入時の重複防止のため、以下のルールでレコードを特定します。

- 単一キー指定: `_keys: email` -> `WHERE email = 'value'` で検索。
- 複合キー指定: `_keys: [company_id, code]` -> `WHERE company_id = 'v1' AND code = 'v2'` で検索。
- 特定結果に基づく挙動:
    - 一致するレコードあり -> そのエンティティを更新して保存。
    - 一致するレコードなし -> 新規エンティティとして保存。

### 3. 参照マップ (`refMap`) のスコープ

- `refMap` は1つのシナリオ実行セッションの間、永続的に保持されます。
- 投入済みの全レコードのIDが蓄積されるため、ファイルを跨いだ参照が可能です。

### 4. エラーハンドリング

- 参照解決失敗: 参照先のラベルが見つからない場合は、即座に `RuntimeException` をスローし、処理を中断します。
- 保存失敗: `Table::save()` が失敗(バリデーションエラー等)した場合、どのファイルのどのレコードで失敗したかを明示した例外をスローし、トランザクションをロールバックします。

実装上の注意点
-------------------------

- 薄い管理層: `ScenarioLoader` 自体に複雑なデータ変換ロジックを持たせず、変換はすべて `Yaml\Loader` に委譲してください。
- テーブル名推論: ファイル名の連番プレフィックス(`01_`, `01-`, `02_`, `02-` など)は自動削除され、その後 `Inflector::tableize()` でCamelCase → snake_caseに変換されます。
    - 例:`shop_products.yml` → `shop_products` テーブル
    - 例:`01_users.yml` → `users` テーブル
    - 例:`02-profiles.yml` → `profiles` テーブル
    - ユーザーは連番をアンダースコアまたはハイフンで自由に選択可能です。
- 依存関係の自動検出: 相互参照(A→B, B→A)はエラーとなります。テストによる検証が推奨されます。
- メモリ管理: 大量データを投入する場合、`refMap` のサイズが増大するため、必要に応じてメモリ消費量に留意してください。

コンストラクタ
-------------------------

```php
new ScenarioLoader(
    string $basePath,
    ?TableLocator $tableLocator = null,
    string $connectionName = 'default'
)
```

- `$basePath`: YAMLファイルが格納されているベースディレクトリの絶対パス。
- `$tableLocator`: CakePHPのTableLocatorインスタンス。省略時は `TableRegistry::getTableLocator()` が使用されます。
- `$connectionName`: 使用するデータベース接続名。デフォルトは `'default'`。

利用サンプル
-------------------------

開発者がテストデータや初期データを投入する際は、以下のように `ScenarioLoader` を呼び出します。

```php
use CakeUtility\Yaml\ScenarioLoader;
use Cake\ORM\Locator\TableLocator;

// ベースディレクトリを第一引数に指定
$baseDir = 'config/Seeds/data';
$tableLocator = new TableLocator();
$loader = new ScenarioLoader($baseDir, $tableLocator, 'default');

// シナリオ配下の全データを投入（ファイル名からテーブル名を自動推測、依存関係を自動解決）
$result = $loader->load('users');
// 結果: ['records_inserted' => 15, 'records_updated' => 2]
// ファイル読み込み順序: groups.yml → users.yml → profiles.yml
// (users.yml が groups.yml を参照、profiles.yml が users.yml を参照するため)

// 特定のテーブルのみに限定
$result = $loader->load('users', 'users');

// 複数のテーブルを指定
$result = $loader->load('users', ['users', 'profiles']);

// シナリオ削除（逆順でデータを削除）
$deleted = $loader->clear('users');
// 結果: 削除したレコード数

// 特定のテーブルのみ削除
$deleted = $loader->clear('users', 'users');

// 複数のテーブルを削除
$deleted = $loader->clear('users', ['users', 'profiles']);
```

### 利用パターン

- 全データ投入: `load('scenario_name')` でシナリオ配下の全YAMLファイルを投入(依存関係を自動解決、ファイル名からテーブル名を自動推測)
- 単一テーブル指定: `load('scenario_name', 'users')` でusersテーブルのみを投入
- 複数テーブル指定: `load('scenario_name', ['users', 'profiles'])` で指定テーブルのみを投入
- 複数回呼び出し時のrefMap: 同一セッション内で複数の `load()` を呼び出すと、`refMap` は蓄積されるため、異なるシナリオ間での参照が可能です

### テーブル名推論

ファイル名から自動的にテーブル名に変換されます

```
groups.yml          → groups (テーブル)
users.yml           → users (テーブル)
shop_products.yml   → shop_products (テーブル)
UserProfiles.yml    → user_profiles (テーブル)
```

ルール: `Cake\Utility\Inflector::tableize()` を適用し、CamelCase → snake_caseに変換します。

### 依存関係の解決

例: `scenario-loader` シナリオの場合

```
元の順序（アルファベット順）:
1. groups.yml
2. products.yml
3. profiles.yml      ← users.yml を参照（失敗）
4. shop_products.yml ← shops.yml, products.yml を参照（失敗）
5. shops.yml
6. users.yml

解決後の順序（依存関係を尊重）:
1. groups.yml        → 独立
2. products.yml      → 独立
3. shops.yml         → 独立
4. users.yml         → groups.yml に依存
5. shop_products.yml → shops.yml, products.yml に依存
6. profiles.yml      → users.yml に依存
```

### パラメータ

#### load() メソッド

```php
$result = $loader->load(string $scenarioName, string|array|null $tableNames = null): array
```

- `$scenarioName`: シナリオ名(ディレクトリ名またはファイル名、拡張子なし)
- `$tableNames`: テーブル名フィルター
    - `null` (省略): シナリオ配下の全ファイルを投入
    - 文字列: 特定の1テーブルのみを投入
        - 指定形式: `'users'` または `'Plugin.Users'`(プラグイン修飾形式)
        - Plugin修飾形式の場合、自動的にテーブル名部分を抽出して正規化されます
    - 文字列の配列: 指定されたテーブルのみを投入
- 戻り値: `['records_inserted' => int, 'records_updated' => int]`

#### clear() メソッド

```php
$deleted = $loader->clear(string $scenarioName, string|array|null $tableNames = null): int
```

- `$scenarioName`: シナリオ名(ディレクトリ名またはファイル名、拡張子なし)
- `$tableNames`: テーブル名フィルター
    - `null` (省略): シナリオ配下の全テーブルを削除対象とする
    - 文字列: 特定の1テーブルのみを削除
        - 指定形式: `'users'` または `'Plugin.Users'`(プラグイン修飾形式)
        - Plugin修飾形式の場合、自動的にテーブル名部分を抽出して正規化されます
    - 文字列の配列: 指定されたテーブルのみを削除
- 戻り値: 削除したレコード数
