Locale Middleware - クイックスタート
====================================

`CakeUtility\I18n\LocaleMiddleware` は、URLパラメータ、Cookie、Accept-Languageヘッダーに基づいて自動的にアプリケーションのロケールを切り替えるミドルウェアです。

何ができるのか
-------------------------

- ユーザーが `?lang=en_US` のようにURLパラメータで言語を切り替えられます
- 指定された言語はCookieに保存され、次回訪問時に復元されます
- ブラウザの言語設定も自動で検出されます(フォールバック)

優先順位
-------------------------

ロケールは以下の順序で判定されます

1. **URL パラメータ** (`?lang=en_US`): ユーザーの明示的な選択
2. Cookie: 前回保存された言語設定
3. Accept-Language ヘッダー: ブラウザの言語設定

この順序により、ユーザーの明示的な操作が最優先されます。

5分で始める
-------------------------

### ステップ1: ミドルウェアを登録

`src/Application.php` の `middleware()` メソッドに以下を追加します。

```php
use CakeUtility\I18n\LocaleMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    // ... 既存のミドルウェア

    $middlewareQueue->add(new LocaleMiddleware());
    
    return $middlewareQueue;
}
```

### ステップ2: ロケールディレクトリを作成

`resources/locales/` 配下に対応言語のディレクトリを作成します。

```
resources/locales/
├ ja_JP/
│   └ default.po
└ en_US/
    └ default.po
```

これで自動的に認識されます。新しいロケールを追加する際も、ディレクトリを作成するだけでOKです。

使い方
-------------------------

### 言語切り替えリンクを作成

テンプレートで言語切り替えリンクを配置します。

```php
// templates/element/language_selector.php
<?php
$locales = [
    'ja_JP' => '日本語',
    'en_US' => 'English',
];
?>

<div class="language-selector">
    <?php foreach ($locales as $locale => $label): ?>
        <?= $this->Html->link(
            $label,
            ['?' => ['lang' => $locale]]
        ) ?>
    <?php endforeach; ?>
</div>
```

### 現在のロケールを表示

```php
// テンプレート内で
Current locale: <?= I18n::getLocale() ?>
```

### Cookie をクリア

言語設定をリセットする場合:

```
?lang=
```

空のパラメータを指定するとCookieがクリアされ、ブラウザの言語設定にフォールバックします。

設定
-------------------------

### デフォルトロケール

フォールバック時のデフォルトロケールは、`config/app.php` で設定します。

```php
// config/app.php
return [
    'App' => [
        'defaultLocale' => 'ja_JP',
        // ...
    ],
];
```

トラブルシューティング
-------------------------

### Q: ロケール判定が正しく動作しない

**A**: 以下をチェックしてください。

1. **resources/locales/ ディレクトリが存在するか**
    - ディレクトリ名がアンダースコア形式(`ja_JP` など)になっているか
    - ディレクトリ内に翻訳ファイル(`.po` など)が存在するか
2. **ミドルウェアが登録されているか**
    - `src/Application.php` の `middleware()` メソッドに登録されているか
3. **Accept-Language ヘッダーの形式**
    - ブラウザから送信されているヘッダー値を確認(ブラウザ開発者ツール)
    - 形式が `en-US,en;q=0.9` のようになっているか

### Q: URL パラメータの言語切り替えが機能しない

**A**: 以下をチェックしてください。

1. URLパラメータ名が正確に `lang` か(大文字小文字を区別)
2. 指定している言語がサポート対象か(`resources/locales/` に該当ディレクトリがあるか)
3. ブラウザのアドレスバーに `?lang=ja_JP` のように指定されているか

### Q: Cookie が保存されない

ブラウザの設定を確認してください

- Cookieを許可しているか
- 開発環境でlocalhostから発行されたCookieがまさしく保存されているか
