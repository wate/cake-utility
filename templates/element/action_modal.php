<?php

declare(strict_types=1);

/**
 * @var \Cake\View\View $this
 * @var string $id モーダルの一意識別子
 * @var string $title モーダルヘッダーのタイトル
 * @var string $name 対象名（message内の{name}を置換）
 * @var string $action 動作名（message内の{action}を置換。例: 削除、編集）
 * @var string|null $message 確認メッセージ（nullで定型文、{name}や{action}を置換）
 * @var string|null $bodyUrl bodyUrl(HTMXで取得するURL)
 * @var string $method 送信メソッド（POST/DELETE等）
 * @var string|false $actionText アクションボタンの文言（falseで非表示）
 * @var string|false $cancelText キャンセルボタンの文言（falseで非表示）
 * @var array<string, string> $modalClasses CSSクラス設定
 */

$id = $id ?? 'action-modal';
$title = $title ?? __d('cake_utility', 'Confirm');
$name = $name ?? '';
$action = $action ?? __d('cake_utility', 'Execute');
$actionText = $actionText ?? $action;
$message = ($message ?? null) === null
    ? ($name !== ''
        ? __d('cake_utility', 'Are you sure you want to {action} {name}?')
        : __d('cake_utility', 'Are you sure you want to {action}?'))
    : $message;
$message = strtr($message, ['{name}' => $name, '{action}' => $action]);
$bodyUrl = $bodyUrl ?? null;
$method = $method ?? 'POST';
$cancelText = $cancelText ?? __d('cake_utility', 'Cancel');

$modalClasses = array_merge([
    'modal' => 'modal-lg',
    'header' => '',
    'body' => 'p-3',
    'footer' => '',
    'actionBtn' => 'btn btn-primary',
    'cancelBtn' => 'btn btn-secondary',
], ($modalClasses ?? []));
?>
<div class="modal fade" id="<?= h($id) ?>" tabindex="-1" role="dialog" aria-labelledby="<?= h($id) ?>-label" aria-hidden="true">
    <div class="modal-dialog <?= h($modalClasses['modal']) ?>" role="document">
        <div class="modal-content">
            <div class="modal-header <?= h($modalClasses['header']) ?>">
                <h5 class="modal-title" id="<?= h($id) ?>-label"><?= h($title) ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body <?= h($modalClasses['body']) ?>">
                <?php if ($message) { ?>
                    <p class="action-modal-message"><?= h($message) ?></p>
                <?php } ?>
                <div class="action-modal-body-content" id="<?= h($id) ?>-body-content">
                    <?php if ($bodyUrl) { ?>
                        <!-- HTMXで内容を読み込む -->
                    <?php } ?>
                </div>
                <?= $this->Form->hidden('_csrfToken', [
                    'value' => $this->request->getAttribute('csrfToken'),
                    'secure' => false,
                ]) ?>
                <?= $this->Form->hidden('_method', ['value' => $method, 'secure' => false]) ?>
            </div>
            <div class="modal-footer <?= h($modalClasses['footer']) ?>">
                <?php if ($cancelText !== false) { ?>
                    <button type="button" class="btn <?= h($modalClasses['cancelBtn']) ?>" data-dismiss="modal">
                        <?= h($cancelText) ?>
                    </button>
                <?php } ?>
                <?php if ($actionText !== false) { ?>
                    <button type="button" class="btn <?= h($modalClasses['actionBtn']) ?>" id="<?= h($id) ?>-action">
                        <?= h($actionText) ?>
                    </button>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
