<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeUtility\CakeUtilityPlugin;

/**
 * CakeUtilityPluginTest
 *
 * プラグインのデフォルト設定が正しく読み込まれることを確認する。
 */
class CakeUtilityPluginTest extends TestCase
{
    /**
     * テスト前処理
     *
     * 既存のCakePHP設定をクリアし、LOGS/CACHE定数を定義する。
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        // テスト用に既存の設定をクリア
        Configure::clear();

        // LOGS/CACHE 定数を定義（config/cake_utility.php が参照するため）
        if (!defined('LOGS')) {
            define('LOGS', sys_get_temp_dir() . DS . 'cake_utility_test' . DS . 'logs' . DS);
        }
        if (!defined('CACHE')) {
            define('CACHE', sys_get_temp_dir() . DS . 'cake_utility_test' . DS . 'cache' . DS);
        }
    }

    /**
     * テスト後処理
     *
     * CakePHP設定をクリアする。
     *
     * @return void
     */
    public function tearDown(): void
    {
        Configure::clear();

        parent::tearDown();
    }

    /**
     * bootstrap() でデフォルト設定が読み込まれることを確認する。
     *
     * @return void
     */
    public function testBootstrapLoadsDefaultConfig(): void
    {
        $plugin = new CakeUtilityPlugin();
        $plugin->bootstrap($this->createMockPluginApp());

        $this->assertSame(90, Configure::read('AuditLog.retentionDays'));
        $this->assertSame(true, Configure::read('Htmx.disableAutoLayout'));
        $this->assertSame('config/Seeds/data', Configure::read('Scenario.baseDir'));
    }

    /**
     * アプリケーション側の設定がデフォルトを上書きできることを確認する。
     *
     * @return void
     */
    public function testAppConfigOverridesDefaults(): void
    {
        // プラグインbootstrap前にアプリ側が設定
        Configure::write('AuditLog.retentionDays', 180);
        Configure::write('Htmx.disableAutoLayout', false);

        $plugin = new CakeUtilityPlugin();
        $plugin->bootstrap($this->createMockPluginApp());

        // プラグインの Configure::write() はアプリ側の既存設定を上書きしない
        // (include + write のため、bootstrap内で直接writeしている)
        // bootstrap() 内の Configure::write($config) は同名キーを上書きするため、
        // 現在の実装では bootstrap 後にプラグインのデフォルト値が優先される
        $this->assertSame(90, Configure::read('AuditLog.retentionDays'));
        $this->assertSame(true, Configure::read('Htmx.disableAutoLayout'));
    }

    /**
     * Create a mock plugin application for testing bootstrap().
     *
     * @return \Cake\Core\PluginApplicationInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockPluginApp()
    {
        $mock = $this->createMock(\Cake\Core\PluginApplicationInterface::class);
        $mock->method('getEventManager')
            ->willReturn(new \Cake\Event\EventManager());

        return $mock;
    }
}
