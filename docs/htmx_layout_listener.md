HTMX Layout Listener 利用方法
=============================

概要
-------------------------

HtmxLayoutListenerは、HTMXリクエスト時にCakePHPのレイアウトレンダリングを
自動的に無効化するイベントリスナーである。
HTMXの部分更新に不要な `<html>` や `<body>` を含むレイアウトHTMLが
レスポンスに含まれるのを防ぐ。

使い方
-------------------------

プラグインを有効化すれば自動的に適用される。
アプリケーション側で追加の記述は一切不要。

### 無効にする場合

設定キー `Htmx.disableAutoLayout` で制御できる。詳細は[設定ガイド](configuration.md)を参照。

仕組み
-------------------------

1. コントローラの `beforeRender` イベントを購読する
2. リクエストヘッダー `HX-Request` の有無を確認する
3. ヘッダーが `true` の場合、`ViewBuilder::disableAutoLayout()` を呼び出してレイアウトを無効化する
4. 通常のブラウザアクセス(`HX-Request` ヘッダーなし)には一切影響を与えない

注意事項
-------------------------

### 全コントローラに適用される

このリスナーはプラグインのイベントマネージャー経由でアプリケーション全体に登録される。そのため**すべてのコントローラ**の `beforeRender` でレイアウト無効化が判定される。HTMXを使わないコントローラでも `HX-Request` ヘッダーが送信されていればレイアウトが無効化されるが、通常のブラウザアクセスに `HX-Request` ヘッダーが付与されることはないため、実質的な影響はない。

### 特定のコントローラでリスナーをスキップしたい場合

どうしても特定のコントローラだけレイアウトを維持したい場合は、`Controller.beforeRender` でリスナーより先にレイアウトを再有効化するか、リスナー登録自体をスキップする方法を検討する。

#### 方法1: beforeRender でレイアウトを再有効化する

```php
// 特定のコントローラの beforeRender または beforeFilter
public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);
    $this->viewBuilder()->enableAutoLayout();
}
```

#### 方法2: リスナーを手動登録に切り替え、登録対象を絞り込む

`Configure::write('Htmx.disableAutoLayout', false)` で自動登録を無効にした後、必要なコントローラのみを対象に個別のイベントリスナーを実装する。

### HTMX 以外の部分更新との併用

`fetch()` や `FetchIt` など `HX-Request` ヘッダーを送信する他のライブラリでも同じ処理が適用される。これによりレイアウト無効化が漏れず、部分更新が正しく動作する。
