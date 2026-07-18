<?php

declare(strict_types=1);

namespace CakeUtility\View\Helper;

use Cake\View\Helper;

use function Cake\I18n\__d;

/**
 * ActionModalHelper
 *
 * Bootstrap 4/AdminLTE 3 のモーダルを起動するトリガーボタンを出力する。
 * モーダルのマークアップは共有element `action_modal.php` が担当し、
 * 駆動用JS `action-modal.js` が data-* 属性を読み取って動作する。
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\FormHelper $Form
 */
class ActionModalHelper extends Helper
{
    /**
     * 使用するヘルパー
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $helpers = ['Html', 'Form', 'Url'];

    /**
     * 汎用ベースメソッド。トリガーボタンのHTMLを返す。
     *
     * @param array $options オプション配列
     * - url (string|array): 確定時のPOST先URL
     * - name (string): 対象名（省略時 ''）
     * - action (string): 動作名（省略時 'Execute'）
     * - actionText (string|false): アクションボタンの文言（falseで非表示、省略時 actionと同じ）
     * - cancelText (string|false): キャンセルボタンの文言（falseで非表示、省略時 'Cancel'）
     * - method (string): 送信メソッド（省略時 'POST'）
     * - bodyUrl (string|null): HTMXでモーダルに読み込むURL（省略時 null）
     * - title (string): モーダルヘッダーのタイトル（省略時 'Confirm'）
     * - message (string|null): 確認メッセージ（nullで定型文、{name}/{action}を置換）
     * - modalClasses (array): モーダル各要素のCSSクラス（省略時は既定値）
     * - class (string): トリガーボタンのCSSクラス（省略時 'btn btn-secondary'）
     * - target (string): モーダル要素のid（省略時 'action-modal'）
     * @return string トリガーボタンのHTML
     */
    public function button(array $options = []): string
    {
        $options += [
            'url' => '',
            'name' => '',
            'action' => __d('cake_utility', 'Execute'),
            'actionText' => null,
            'cancelText' => null,
            'method' => 'POST',
            'bodyUrl' => null,
            'title' => __d('cake_utility', 'Confirm'),
            'message' => null,
            'modalClasses' => [],
            'class' => 'btn btn-secondary',
            'target' => 'action-modal',
        ];

        $url = is_string($options['url']) ? $options['url'] : $this->Url->build($options['url'], ['escape' => false]);
        $actionText = $options['actionText'] ?? $options['action'];
        $cancelText = $options['cancelText'] ?? __d('cake_utility', 'Cancel');
        $modalClasses = !empty($options['modalClasses']) ? json_encode($options['modalClasses'], JSON_UNESCAPED_UNICODE) : '';

        $attrs = [
            'type' => 'button',
            'class' => $options['class'],
            'data-action-modal' => '',
            'data-url' => $url,
            'data-name' => $options['name'],
            'data-action' => $options['action'],
            'data-action-text' => $actionText,
            'data-cancel-text' => $cancelText,
            'data-method' => $options['method'],
            'data-title' => $options['title'],
            'data-target' => $options['target'],
        ];

        if ($options['bodyUrl'] !== null) {
            $bodyUrl = is_string($options['bodyUrl']) ? $options['bodyUrl'] : $this->Url->build($options['bodyUrl'], ['escape' => false]);
            $attrs['data-body-url'] = $bodyUrl;
        }

        if ($options['message'] !== null) {
            $attrs['data-message'] = $options['message'];
        }

        if ($modalClasses !== '') {
            $attrs['data-modal-classes'] = $modalClasses;
        }

        // actionTextがfalseの場合はdata-action-textを省略（JS側で未設定扱い）
        // ボタン文言はaction値にフォールバック
        if ($options['actionText'] === false) {
            unset($attrs['data-action-text']);
            $actionText = $options['action'];
        }
        if ($options['cancelText'] === false) {
            unset($attrs['data-cancel-text']);
        }

        return $this->Html->tag('button', $actionText, $attrs);
    }

    /**
     * 削除用ラッパー
     *
     * @param array $options オプション配列（button()と同じ）
     * @return string トリガーボタンのHTML
     */
    public function deleteButton(array $options = []): string
    {
        $options += [
            'action' => __d('cake_utility', 'Delete'),
            'title' => __d('cake_utility', 'Delete Confirmation'),
            'class' => 'btn btn-danger',
        ];

        return $this->button($options);
    }
}
