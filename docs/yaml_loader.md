YAML Data Loader 仕様
=========================

`CakeUtility\Yaml\Loader` は、YAML形式のテストデータやシードデータをDB投入可能な形式に変換するためのユーティリティです。

基本コンセプト
-------------------------

本ローダーは、データの「パース(構造化)」と「参照解決(ID置換)」を分離して処理します。これにより、Fixtureのように投入前にIDが確定しているケースと、Seedのように投入後にIDが確定するケースの両方に対応します。

記述仕様
-------------------------

### 1. レコードの識別子 (`_ref`, `_keys`)

- 制御キーの仕様: アンダースコア (`_`) で始まるすべてのキーは、Loader内部の制御用として扱われます。これらのキーは参照解決やレコード特定に使用され、**実際のデータベース投入データからは自動的に除外されます**。そのため、DBのカラム名にアンダースコアから始まる名前を使用することは避けてください。
- `_ref` (シンボリックID): ルートに `_ref` キーを指定することで、レコードにラベルを付与できます。他のレコードからの参照に使用されます。
- `_keys` (特定用カラム): 既存レコードを特定するためのカラム名を指定します。指定されたカラムの値を用いてDBを検索し、一致したレコードを更新(Upsert)します。
    - 単一キー: 文字列で指定します。例: `_keys: email`
    - 複合キー: 配列で指定します。例: `_keys: [ shop_id, item_code ]`
    - 主キー指定: `_keys: [ id ]` とすることで、実質的に主キーによる直接指定が可能です。

```yaml
- _ref: user_admin
  _keys: email # emailカラムの値を用いてレコードを特定して更新
  username: admin_updated
  email: admin@example.com
```

### 2. データの参照 (`ref:`)

他のレコードを参照する場合、値に `ref:` プレフィックスを付けた文字列を指定します。

```yaml
- _ref: profile_admin
  user_id: "ref:user_admin"  # ダブルクォートで囲み、user_admin ラベルのレコードのIDに置換される
  bio: "管理者プロフィール"
```

- 解決タイミング: `resolveRefs()` メソッドの呼び出し時に、リファレンスマップに基づいて実際のIDに置換されます。
- エラーハンドリング: 参照先のラベルがマップに存在しない場合は `RuntimeException` がスローされます。

### 3. 日時指定 (`@`)

日時の指定に `@` から始まる記述を使用できます。`@` 以降にPHPの `DateTime` が解析可能な文字列を指定することで、動的な日時を生成します。

```yaml
- _ref: user_admin
  created_at: "@now"            # DateTime型: 現在の日時 (2026-07-09 15:30:45)
  birthday: "@today"            # Date型: 今日の日付 (2026-07-09)
  start_time: "@now +12 hours"  # Time型: 現在から12時間後の時刻 (03:30:45)
```

- 精度の使い分け:
    - `@now`: 時刻まで含めた現在日時を想定。
    - `@today`: 日付基準(時刻00:00:00)を想定。
- 相対指定: `today` や `now` の後に `+1 day`, `-1 month` などの相対表現を続けることで、柔軟な日時指定が可能です。
- 処理: `parse()` フェーズで解決され、`normalizeTypes()` フェーズにてDBスキーマ (Date, Time, DateTime) に合わせた最適なフォーマットに変換されます。

### 4. 型正規化 (Type Normalization)

テーブルスキーマ情報 (`Table::getSchema()`) が提供された場合、以下の変換が自動的に行われます。

- Boolean型: YAMLの `true`/`false` (または `1`/`0`) -> PHPの `bool` 型へ変換。
- JSON型: YAMLの配列/連想配列 -> PHPの配列/オブジェクトへ変換。
- Integer型: 数値として解釈可能な文字列 -> 整数型へ変換。

#### 明示的指定 (Explicit)

自動判定で意図しない型になる場合は、YAML側の記述で制御します。

- 文字列強制: 値をダブルクォート (`"00123"`) で囲むことで、数値ではなく文字列として扱います。

1. `parse(filePath)`:
   YAMLを読み込み、構造化データに変換。この段階で `@` 日時予約語の解決が行われます。
2. (外部処理):
   レコードを投入し、確定したIDを `label => id` の形式でマップに蓄積します。
3. `resolve(data, refMap, context)`:
   `ref:` プレフィックスを持つ値をマップを用いて実際のIDに置換し、同時にテーブルスキーマに基づいた型変換(キャスト)を行います。
   ※ `context` には `Table` オブジェクトまたは `TableSchema` オブジェクトを指定します。

### メソッド仕様

#### parse() メソッド

```php
$records = $loader->parse(string $filePath): array
```

YAMLファイルを読み込み、PHP配列に変換します。

- YAMLの `@now`, `@today` などの予約語が解決されます
- 制御キー(`_ref`, `_keys`)も含まれた状態で返されます
- 戻り値: `[['_ref' => '...', '_keys' => '...', 'column' => 'value'], ...]` の形式

#### resolve() メソッド

```php
$resolved = $loader->resolve(array $records, array $refMap, $context): array
```

`parse()` で取得した配列に対して、参照解決と型変換を実行します。

- `$refMap`: `['ref_label' => id_value, ...]` の形式。`ref:` プレフィックス付きの値がこのマップを用いて置換されます
- `$context`: `Table` または `TableSchema` オブジェクト。型変換に使用されます
- 戻り値: 参照解決済み、型変換済みの配列(制御キーは除外)

#### extractReferences() メソッド

```php
$references = $loader->extractReferences(string $filePath): array
```

YAMLファイルから全ての `ref:` プレフィックス付きの値を抽出します。

- 依存関係グラフの構築に使用されます(`ScenarioLoader` 内部で利用)
- ネストされた構造内の参照も自動抽出します
- 戻り値: `['ref_label1', 'ref_label2', ...]` の形式

実装サンプル
-------------------------

### 基本的な変換フロー

`Yaml\Loader` は純粋な変換エンジンであり、DBへの保存や参照マップの構築・管理は呼び出し側(例:`ScenarioLoader`)が行います。

```php
$loader = new Loader();

// 1. YAMLをパースして構造化データ（配列）を取得
// この段階で @now などの日時予約語が解決されます
$records = $loader->parse('path/to/data.yml');

// 2. 参照解決と型変換を実行
// 準備済みのリファレンスマップとスキーマ情報を渡し、DB投入可能な形式に変換します
$refMap = [
    'user_admin' => 1, // 事前に確定しているIDマップ
];
$schema = $this->Table->getSchema();

$resolvedRecords = $loader->resolve($records, $refMap, $schema);

// $resolvedRecords は、ref: が実IDに置換され、
// 型がキャストされた、DB保存可能な配列になります。
```

#### 前処理として必要な準備

- リファレンスマップの構築: `resolve()` を呼び出す前に、YAML内で `ref:label` として参照されるすべてのラベルが `$refMap` に登録されている必要があります。投入順序の制御や、既存レコードからのID抽出によって準備します。
- スキーマ情報の提供: 型変換を利用する場合は、`Table` または `TableSchema` オブジェクトを第3引数に指定してください。
