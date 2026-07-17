<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\View\Helper;

use Cake\Http\ServerRequest;
use Cake\Http\Response;
use Cake\Routing\Route\DashedRoute;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Cake\View\ViewBuilder;
use CakeUtility\View\Helper\ActionModalHelper;

/**
 * ActionModalHelperTest
 */
class ActionModalHelperTest extends TestCase
{
    /**
     * @var \CakeUtility\View\Helper\ActionModalHelper
     */
    protected ActionModalHelper $helper;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        // Register minimal route for array URL generation (DashedRoute for production parity)
        Router::reload();
        Router::createRouteBuilder('/')->setRouteClass(DashedRoute::class)->connect('/{controller}/{action}/*', []);

        $request = new ServerRequest();
        $response = new Response(['charset' => 'UTF-8']);
        $view = new View($request, $response);
        $view->loadHelper('Html');
        $view->loadHelper('Form');
        $view->loadHelper('Url');
        $this->helper = new ActionModalHelper($view);
    }

    /**
     * button() が data-action-modal 属性を含むボタンを出力するかテスト
     *
     * @return void
     */
    public function testButtonContainsDataAttribute(): void
    {
        $result = $this->helper->button(['url' => '/test/delete']);
        $this->assertStringContainsString('data-action-modal', $result);
        $this->assertStringContainsString('<button', $result);
        $this->assertStringContainsString('</button>', $result);
    }

    /**
     * button() に url, name, action を渡した場合のdata-*属性をテスト
     *
     * @return void
     */
    public function testButtonDataAttributes(): void
    {
        $result = $this->helper->button([
            'url' => '/products/delete/1',
            'name' => '商品A',
            'action' => '削除',
        ]);

        $this->assertStringContainsString('data-url="/products/delete/1"', $result);
        $this->assertStringContainsString('data-name="商品A"', $result);
        $this->assertStringContainsString('data-action="削除"', $result);
    }

    /**
     * button() の actionText 省略時は action と同じ値になるかテスト
     *
     * @return void
     */
    public function testButtonActionTextDefaultsToAction(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'action' => '削除',
        ]);

        $this->assertStringContainsString('data-action-text="削除"', $result);
        $this->assertStringContainsString('>削除</button>', $result);
    }

    /**
     * button() に actionText を明示した場合のテスト
     *
     * @return void
     */
    public function testButtonActionTextExplicit(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'action' => '削除',
            'actionText' => '削除する',
        ]);

        $this->assertStringContainsString('data-action-text="削除する"', $result);
        $this->assertStringContainsString('>削除する</button>', $result);
    }

    /**
     * button() で actionText=false の場合に data-action-text が出力されないかテスト
     *
     * @return void
     */
    public function testButtonActionTextFalse(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'action' => '削除',
            'actionText' => false,
        ]);

        $this->assertStringNotContainsString('data-action-text', $result);
    }

    /**
     * button() で cancelText=false の場合に data-cancel-text が出力されないかテスト
     *
     * @return void
     */
    public function testButtonCancelTextFalse(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'cancelText' => false,
        ]);

        $this->assertStringNotContainsString('data-cancel-text', $result);
    }

    /**
     * button() に bodyUrl を渡した場合のテスト
     *
     * @return void
     */
    public function testButtonBodyUrl(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'bodyUrl' => '/products/view/1',
        ]);

        $this->assertStringContainsString('data-body-url="/products/view/1"', $result);
    }

    /**
     * button() に method を渡した場合のテスト
     *
     * @return void
     */
    public function testButtonMethod(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'method' => 'DELETE',
        ]);

        $this->assertStringContainsString('data-method="DELETE"', $result);
    }

    /**
     * deleteButton() が削除用の既定値を持つかテスト
     *
     * @return void
     */
    public function testDeleteButtonDefaults(): void
    {
        $result = $this->helper->deleteButton([
            'url' => '/products/delete/1',
        ]);

        $this->assertStringContainsString('data-action="Delete"', $result);
        $this->assertStringContainsString('data-action-text="Delete"', $result);
        $this->assertStringContainsString('data-title="Delete Confirmation"', $result);
        $this->assertStringContainsString('btn btn-danger', $result);
    }

    /**
     * button() に title を渡した場合のテスト
     *
     * @return void
     */
    public function testButtonTitle(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'title' => 'カスタム確認',
        ]);

        $this->assertStringContainsString('data-title="カスタム確認"', $result);
    }

    /**
     * button() に message を渡した場合のテスト
     *
     * @return void
     */
    public function testButtonMessage(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'message' => '{name}を本当に{action}しますか？',
        ]);

        $this->assertStringContainsString('data-message="{name}を本当に{action}しますか？"', $result);
    }

    /**
     * button() に CakePHP配列形式の url を渡した場合のテスト
     * （_urlToString パスが正しく動作するか検証）
     *
     * @return void
     */
    public function testButtonArrayUrl(): void
    {
        $result = $this->helper->button([
            'url' => ['controller' => 'Products', 'action' => 'delete', 1],
        ]);

        $this->assertStringContainsString('data-url', $result);
        $this->assertStringNotContainsString('data-url="Array"', $result);
        $this->assertStringContainsString('data-url="/products/delete/1"', $result);
    }

    /**
     * deleteButton() に CakePHP配列形式の url を渡した場合のテスト
     * （実際のテンプレートでの使用パターン）
     *
     * @return void
     */
    public function testDeleteButtonArrayUrl(): void
    {
        $result = $this->helper->deleteButton([
            'url' => ['controller' => 'Products', 'action' => 'delete', 1],
            'name' => '商品A',
        ]);

        $this->assertStringContainsString('data-url', $result);
        $this->assertStringNotContainsString('data-url="Array"', $result);
        $this->assertStringContainsString('data-action="Delete"', $result);
        $this->assertStringContainsString('data-url="/products/delete/1"', $result);
        $this->assertStringContainsString('btn btn-danger', $result);
    }

    /**
     * button() に CakePHP配列形式の bodyUrl を渡した場合のテスト
     *
     * @return void
     */
    public function testButtonArrayBodyUrl(): void
    {
        $result = $this->helper->button([
            'url' => '/test',
            'bodyUrl' => ['controller' => 'Products', 'action' => 'editForm', 1],
        ]);

        $this->assertStringContainsString('data-body-url', $result);
        $this->assertStringNotContainsString('data-body-url="Array"', $result);
        $this->assertStringContainsString('data-body-url="/products/edit-form/1"', $result);
    }
}
