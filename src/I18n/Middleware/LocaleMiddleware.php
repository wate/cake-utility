<?php

namespace CakeUtility\I18n\Middleware;

use Cake\Core\Configure;
use Cake\Http\Cookie\Cookie;
use Cake\I18n\I18n;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * LocaleMiddleware
 *
 * Automatically detects and sets the application locale based on request headers,
 * query parameters, or session-based user preferences.
 *
 * @var array $config Configuration for supported locales and default fallback.
 */
class LocaleMiddleware implements MiddlewareInterface
{
    protected $config = [
        'parameter' => 'lang',
        'cookie' => [
            'name' => 'locale',
            'expires' => '+1 month',
            'path' => '/',
        ],
    ];


    /**
     * Constructor
     *
     * @param array<string, mixed> $config The options to use.
     * @see \Cake\Http\Middleware\HttpsEnforcerMiddleware::$config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + $this->config;
    }

    /**
     * Process the request and add rate limiting
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The handler
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. URLパラメータ確認
        $queryParam = $request->getQuery($this->config['parameter']);
        $newLocale = null;
        $supported = $this->getSupportedLocales();
        $clearCookie = false;

        if ($queryParam !== null) {
            // パラメータが明示的に指定されている場合
            if ($queryParam === '') {
                // ?lang= で空の場合は言語設定をリセット
                $clearCookie = true;
            } elseif (in_array($queryParam, $supported, true)) {
                // サポートされているロケール
                $newLocale = $queryParam;
            }
        } else {
            // URLパラメータがない場合
            // セッション/Cookieを確認
            $value = null;
            if ($request->getCookieCollection()->has($this->config['cookie']['name'])) {
                // クッキーが存在する場合の処理
                $value = $request->getCookieCollection()->get($this->config['cookie']['name'])->getValue();
            }
            $newLocale = $value;

            // リクエストヘッダー(Accept-Language)を解析
            if (!$newLocale) {
                $header = $request->getHeaderLine('Accept-Language');
                if ($header) {
                    $newLocale = $this->parseAcceptLanguage($header);
                }
            }
        }

        $response = $handler->handle($request);

        if ($clearCookie) {
            // Cookie をリセット（有効期限を過去に設定）
            $cookie = Cookie::create(
                $this->config['cookie']['name'],
                '',
                [
                    'expires' => new \DateTime('1970-01-01'),
                    'path' => $this->config['cookie']['path'],
                ]
            );
            $response = $response->withCookie($cookie);
        } elseif ($newLocale) {
            I18n::setLocale($newLocale);
            $cookie = Cookie::create(
                $this->config['cookie']['name'],
                $newLocale,
                [
                    'expires' => new \DateTime($this->config['cookie']['expires']), // 有効期限
                    'path' => $this->config['cookie']['path'],                      // パス
                ]
            );
            $response = $response->withCookie($cookie);
        }
        return $response;
    }

    /**
     * Accept-Languageヘッダーを解析して最適なロケールを取得
     *
     * ヘッダー内の言語タグを優先度（q値）の降順でソートし、
     * アプリケーションでサポートしているロケールから最初にマッチするものを返します。
     * 言語タグはハイフン形式（例：ja-JP）からアンダースコア形式（ja_JP）に変換します。
     *
     * @param string $header Accept-Languageヘッダー値
     * @return string|null マッチしたロケール（例：ja_JP, en_US）、マッチしない場合はnull
     */
    private function parseAcceptLanguage(string $header): ?string
    {
        $preferences = [];

        // ヘッダーを解析してq値とともに配列化
        foreach (explode(',', $header) as $item) {
            $item = trim($item);
            if (empty($item)) {
                continue;
            }

            // 言語タグとq値を分離
            $parts = explode(';', $item);
            $language = trim($parts[0]);
            $quality = 1.0;

            if (isset($parts[1])) {
                // q値を抽出（例：q=0.8）
                if (preg_match('/q\s*=\s*([\d.]+)/', $parts[1], $matches)) {
                    $quality = (float) $matches[1];
                }
            }

            // 言語タグをアンダースコア形式に変換（en-US → en_US）
            $locale = str_replace('-', '_', $language);
            $preferences[] = ['locale' => $locale, 'quality' => $quality];
        }

        // q値の高い順にソート
        usort($preferences, function ($a, $b) {
            return $b['quality'] <=> $a['quality'];
        });

        // サポートしているロケールから最初にマッチするものを返す
        $supported = $this->getSupportedLocales();
        foreach ($preferences as $item) {
            $locale = $item['locale'];

            // 完全一致を確認
            if (in_array($locale, $supported, true)) {
                return $locale;
            }

            // 言語部分だけが一致するものを確認（例：ja_JP と ja のマッチング）
            $language = explode('_', $locale)[0];
            foreach ($supported as $supportedLocale) {
                if (strpos($supportedLocale, $language) === 0) {
                    return $supportedLocale;
                }
            }
        }

        return null;
    }

    /**
     * サポートされているロケール一覧を取得
     *
     * resources/locales/ ディレクトリに存在するロケールディレクトリから自動検出します。
     * デフォルトロケールも常に含まれます。
     *
     * @return array<string> サポートされているロケール（例：['ja_JP', 'en_US']）
     */
    private function getSupportedLocales(): array
    {
        $localesPaths = Configure::read('App.paths.locales');
        $localesPath = is_array($localesPaths) ? $localesPaths[0] : null;
        $defaultLocale = Configure::read('App.defaultLocale');
        $locales = [];

        // localesディレクトリを走査して、ロケールディレクトリを検出
        if ($localesPath && is_dir($localesPath)) {
            foreach (scandir($localesPath) as $dir) {
                // ドット（. と ..)とファイル（.pot等）を除外
                if ($dir !== '.' && $dir !== '..' && is_dir($localesPath . $dir)) {
                    $locales[] = $dir;
                }
            }
        }

        // デフォルトロケールを追加（既存しなかった場合）
        if (!in_array($defaultLocale, $locales, true)) {
            array_unshift($locales, $defaultLocale);
        }

        return $locales;
    }
}
