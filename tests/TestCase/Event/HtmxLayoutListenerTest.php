<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Event;

use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\TestSuite\TestCase;
use CakeUtility\Event\HtmxLayoutListener;

/**
 * HtmxLayoutListenerTest
 */
class HtmxLayoutListenerTest extends TestCase
{
    /**
     * HX-Requestヘッダーがある場合にレイアウトが無効化されるかテスト
     *
     * @return void
     */
    public function testDisableLayoutOnHtmxRequest(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
            'HTTP_HX_REQUEST' => 'true',
        ]);
        $controller = new Controller($request);
        $listener = new HtmxLayoutListener();
        $event = new Event('Controller.beforeRender', $controller);

        $listener->disableLayout($event);

        $this->assertFalse($controller->viewBuilder()->isAutoLayoutEnabled());
    }

    /**
     * HX-Requestヘッダーがない場合にレイアウトが維持されるかテスト
     *
     * @return void
     */
    public function testKeepLayoutOnNormalRequest(): void
    {
        $request = ServerRequestFactory::fromGlobals([
            'SERVER_NAME' => 'localhost',
            'REQUEST_METHOD' => 'GET',
        ]);
        $controller = new Controller($request);
        $listener = new HtmxLayoutListener();
        $event = new Event('Controller.beforeRender', $controller);

        $listener->disableLayout($event);

        $this->assertTrue($controller->viewBuilder()->isAutoLayoutEnabled());
    }

    /**
     * implementedEvents() が正しいイベント名を返すかテスト
     *
     * @return void
     */
    public function testImplementedEvents(): void
    {
        $listener = new HtmxLayoutListener();
        $events = $listener->implementedEvents();

        $this->assertArrayHasKey('Controller.beforeRender', $events);
        $this->assertSame('disableLayout', $events['Controller.beforeRender']);
    }
}
