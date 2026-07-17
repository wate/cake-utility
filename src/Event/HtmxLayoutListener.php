<?php

declare(strict_types=1);

namespace CakeUtility\Event;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

/**
 * HTMXリクエストを検出し、レイアウトを自動無効化するイベントリスナー
 *
 * Controller.beforeRender イベントを購読し、HX-Requestヘッダーが
 * trueの場合にレイアウトを無効化する。これによりHTMXの部分更新に
 * 不要なレイアウトHTMLが含まれなくなる。
 */
class HtmxLayoutListener implements EventListenerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function implementedEvents(): array
    {
        return [
            'Controller.beforeRender' => 'disableLayout',
        ];
    }

    /**
     * HTMXリクエスト時にレイアウトを無効化する。
     *
     * @param \Cake\Event\EventInterface $event イベントオブジェクト
     * @return void
     */
    public function disableLayout(EventInterface $event): void
    {
        /** @var \Cake\Controller\Controller $controller */
        $controller = $event->getSubject();

        $hxRequest = $controller->getRequest()->getHeaderLine('HX-Request');
        if ($hxRequest === 'true') {
            $controller->viewBuilder()->disableAutoLayout();
        }
    }
}
