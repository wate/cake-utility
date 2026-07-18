<?php

declare(strict_types=1);

namespace CakeUtility;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use CakeUtility\Command\AuditLogPurgeCommand;
use CakeUtility\Event\HtmxLayoutListener;

/**
 * CakeUtility プラグイン
 *
 * 監査ログ(AuditLog)、インポート(Import)、YAMLシナリオ、
 * HTMX連携、ロケール自動判定などの共通機能を提供するプラグイン。
 * アプリケーションのブートストラップ時に設定の読み込みと
 * イベントリスナーの登録を行う。
 */
class CakeUtilityPlugin extends BasePlugin
{
    /**
     * プラグインの設定読み込みとブートストラップ処理を実行する。
     *
     * プラグイン設定ファイル(cake_utility.php)を読み込み、HTMXレイアウト自動無効化の
     * イベントリスナーを設定により登録する。
     *
     * @param \Cake\Core\PluginApplicationInterface $app ホストアプリケーション
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        /** @var array<string, mixed> $config */
        $config = include __DIR__ . '/../config/cake_utility.php';
        Configure::write($config);

        // HTMXリクエスト時にレイアウトを自動無効化（設定で無効化可能）
        /** @var array<string, mixed> $htmxConfig */
        $htmxConfig = Configure::read('Htmx');
        if (($htmxConfig['disableAutoLayout'] ?? true) === true) {
            $app->getEventManager()->on(new HtmxLayoutListener());
        }
    }

    /**
     * プラグインのルートを追加する。
     *
     * /cake-utility パスの配下にプラグインルートを設定する。
     *
     * @param \Cake\Routing\RouteBuilder $routes 更新対象のルートビルダー
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin(
            'CakeUtility',
            ['path' => '/cake-utility'],
            function (RouteBuilder $builder) {
                // Add custom routes here

                $builder->fallbacks();
            }
        );
        parent::routes($routes);
    }

    /**
     * プラグインのミドルウェアを追加する。
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue 更新対象のミドルウェアキュー
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * プラグインのコンソールコマンドを追加する。
     *
     * audit_log_purge コマンドを登録する。
     *
     * @param \Cake\Console\CommandCollection $commands 更新対象のコマンドコレクション
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('audit_log_purge', AuditLogPurgeCommand::class);

        $commands = parent::console($commands);

        return $commands;
    }

    /**
     * アプリケーションコンテナのサービスを登録する。
     *
     * @param \Cake\Core\ContainerInterface $container 更新対象のコンテナ
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        // Add your services here
    }
}
