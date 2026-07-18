<?php

declare(strict_types=1);

/**
 * @var \Cake\View\View $this
 * @var \CakeUtility\Import\PreviewResult $result プレビュー結果
 * @var array<string> $columns 表示するCSV列名
 * @var array<array<string, mixed>> $source 元のCSV行データ
 */

use CakeUtility\Import\PreviewResult;

if (!($result instanceof PreviewResult)) {
    return;
}

$source = $source ?? [];
$columns = $columns ?? [];
$rowErrors = $result->rowErrors();

// エラー行を行番号でマップ
$errorMap = [];
foreach ($rowErrors as $err) {
    $errorMap[$err['row']] = $err['message'];
}
?>
<div class="card card-info">
    <div class="card-header">
        <h3 class="card-title">
            <?= __d('cake_utility', 'Import Preview') ?>
        </h3>
    </div>
    <div class="card-body">
        <div class="callout callout-info">
            <p>
                <strong>
                    <?= __d('cake_utility', '{1} error(s) (Total: {0})', $result->total(), $result->errorCount()) ?>
                </strong>
            </p>
        </div>

        <?php if (!empty($columns) && !empty($source)) { ?>
        <table class="table table-bordered table-striped table-sm mt-3">
            <thead>
                <tr>
                    <?php foreach ($columns as $col) { ?>
                        <th><?= h($col) ?></th>
                    <?php } ?>
                    <th><?= __d('cake_utility', 'Status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($source as $index => $row) {
                    $lineNumber = $index + 2; // ヘッダー行があるため+2
                    $hasError = isset($errorMap[$lineNumber]);
                    ?>
                <tr class="<?= $hasError ? 'table-danger' : '' ?>">
                    <?php foreach ($columns as $col) { ?>
                        <td><?= h($row[$col] ?? '') ?></td>
                    <?php } ?>
                    <td>
                        <?php if ($hasError) { ?>
                            <span class="badge badge-danger">
                                <?= h($errorMap[$lineNumber]) ?>
                            </span>
                        <?php } else { ?>
                            <span class="badge badge-success">
                                <?= __d('cake_utility', 'OK') ?>
                            </span>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </div>
</div>
