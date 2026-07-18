<?php

namespace Cake\Test\TestCase\Plugins\CakeUtility;

use Cake\TestSuite\TestCase;
use Cake\Http\ServerRequestFactory;
use Cake\Http\Response;
use Cake\I18n\I18n;
use Cake\Core\Configure;
use CakeUtility\I18n\Middleware\LocaleMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * LocaleMiddlewareTest
 *
 * LocaleMiddleware によるロケール自動検出・設定の各パターンを検証する。
 */
class LocaleMiddlewareTest extends TestCase
{
    /**
     * @var \CakeUtility\I18n\Middleware\LocaleMiddleware
     */
    protected LocaleMiddleware $middleware;

    /**
     * テスト前処理
     *
     * テスト用ロケールディレクトリの作成とミドルウェアインスタンスの生成を行う。
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        // テスト用に locales ディレクトリをモック
        $tempDir = sys_get_temp_dir() . DS . 'cakephp_test_locales' . DS;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
            mkdir($tempDir . 'ja_JP', 0755, true);
            mkdir($tempDir . 'en_US', 0755, true);
        }

        Configure::write('App.paths.locales', [$tempDir]);
        Configure::write('App.defaultLocale', 'en_US');
        Configure::write('App.encoding', 'UTF-8');
        $this->middleware = new LocaleMiddleware();
        I18n::setLocale('en_US');
    }

    /**
     * テスト後処理
     *
     * ロケールと設定を初期状態に戻す。
     *
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
        I18n::setLocale('en_US');
        Configure::delete('App.paths.locales');
        Configure::delete('App.defaultLocale');
        Configure::delete('App.encoding');
    }

    /**
     * ダミーハンドラを作成
     *
     * @return \Psr\Http\Server\RequestHandlerInterface
     */
    private function createDummyHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle($request): ResponseInterface
            {
                return new Response(['charset' => 'UTF-8']);
            }
        };
    }

    /**
     * Accept-Languageヘッダーに基づいてロケールが設定されるかテスト
     *
     * @return void
     */
    public function testAcceptLanguageHeaderSetsLocale(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,ja;q=0.8',
        ]);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        $this->assertEquals('en_US', I18n::getLocale());
    }

    /**
     * URLパラメータがAccept-Languageより優先されるかテスト
     *
     * @return void
     */
    public function testUrlParameterTakesPrecedence(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ])->withQueryParams(['lang' => 'ja_JP']);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        $this->assertEquals('ja_JP', I18n::getLocale());
    }

    /**
     * 無効なURLパラメータはフォールバックするかテスト
     *
     * @return void
     */
    public function testInvalidUrlParameterFallback(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ])->withQueryParams(['lang' => 'invalid_XX']);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        // 無効なパラメータなので、Accept-Languageにフォールバック
        // Accept-Language に en-US が指定されているので、en_US が返される
        $this->assertEquals('en_US', I18n::getLocale());
    }

    /**
     * ?lang= で Cookie がクリアされるかテスト
     *
     * @return void
     */
    public function testEmptyLangParameterClearsCookie(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'ja-JP',
        ])->withQueryParams(['lang' => '']);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        // Cookie がクリアされたかを確認
        $cookies = $response->getCookieCollection();
        $this->assertTrue($cookies->has('locale'));
        $cookie = $cookies->get('locale');
        $this->assertEquals('', $cookie->getValue());
    }

    /**
     * 言語の部分一致（ja で ja_JP が選ばれる）かテスト
     *
     * @return void
     */
    public function testPartialLanguageMatch(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'ja,en-US;q=0.9',
        ]);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        $this->assertEquals('ja_JP', I18n::getLocale());
    }

    /**
     * q値によって優先度が決まるかテスト
     *
     * @return void
     */
    public function testQValuePriority(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,ja;q=0.9,en-US;q=0.8',
        ]);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        // fr-FR はサポートされていないので、次のq値で最も高い ja_JP が選ばれる
        $this->assertEquals('ja_JP', I18n::getLocale());
    }

    /**
     * Cookie が設定されるかテスト
     *
     * @return void
     */
    public function testCookieIsSet(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
        ])->withQueryParams(['lang' => 'ja_JP']);

        $handler = $this->createDummyHandler();
        $response = $this->middleware->process($request, $handler);

        $cookies = $response->getCookieCollection();
        $this->assertTrue($cookies->has('locale'));
        $cookie = $cookies->get('locale');
        $this->assertEquals('ja_JP', $cookie->getValue());
    }
}
