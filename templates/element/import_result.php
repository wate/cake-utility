<?php

declare(strict_types=1);

/**
 * @var \Cake\View\View $this
 * @var \CakeUtility\Import\ImportResult $result インポート結果
 * @var array<string> $columns 表示するカラム名
 * @var array<array<string, mixed>>|null $source 元のCSV行データ（省略時はエンティティの値を表示）
 */

use CakeUtility\Import\ImportResult;

$result = $result ?? null;
$columns = $columns ?? [];
$source = $source ?? null;

if (!($result instanceof ImportResult)) {
    return;
}

$total = $result->successCount() + $result->errorCount();
$isSuccess = $result->isSuccess();
$savedEntities = $result->savedEntities();
$rowErrors = $result->rowErrors();

// エラー行を行番号でマップ
$errorMap = [];
foreach ($rowErrors as $err) {
    $errorMap[$err['row']] = $err['message'];
}
?>
<div class="card <?= $isSuccess ? 'card-success' : ($result->errorCount() > 0 && $result->successCount() > 0 ? 'card-warning' : 'card-danger') ?>">
    <div class="card-header">
        <h3 class="card-title">
            <?= __d('cake_utility', 'Import Result') ?>
        </h3>
    </div>
    <div class="card-body">
        <div class="callout <?= $isSuccess ? 'callout-success' : 'callout-warning' ?>">
            <p>
                <strong>
                    <?= __d('cake_utility', '{1} succeeded, {2} errors (Total: {0})', $total, $result->successCount(), $result->errorCount()) ?>
                </strong>
            </p>
        </div>

        <?php if (!empty($columns) && (!empty($savedEntities) || !empty($source))) { ?>
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
                <?php if ($source !== null) { ?>
                    <?php foreach ($source as $index => $row) {
                        $lineNumber = $index + 2;
                        $hasError = isset($errorMap[$lineNumber]);
                        ?>
                    <tr class="<?= $hasError ? 'table-danger' : 'table-success' ?>">
                        <?php foreach ($columns as $col) { ?>
                            <td><?= h($row[$col] ?? '') ?></td>
                        <?php } ?>
                        <td>
                            <?php if ($hasError) { ?>
                                <span class="badge badge-danger"><?= h($errorMap[$lineNumber]) ?></span>
                            <?php } else { ?>
                                <span class="badge badge-success"><?= __d('cake_utility', 'Saved') ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                <?php } else { ?>
                    <?php foreach ($savedEntities as $entity) { ?>
                    <tr class="table-success">
                        <?php foreach ($columns as $col) { ?>
                            <td><?= h($entity->get($col) ?? '') ?></td>
                        <?php } ?>
                        <td>
                            <span class="badge badge-success"><?= __d('cake_utility', 'Saved') ?></span>
                        </td>
                    </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <?php if ($result->errorCount() > 0) { ?>
        <div class="mt-3">
            <h5><?= __d('cake_utility', 'Error Details') ?></h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th><?= __d('cake_utility', 'Row') ?></th>
                            <th><?= __d('cake_utility', 'Error Message') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rowErrors as $error) { ?>
                        <tr class="table-danger">
                            <td><?= h($error['row']) ?></td>
                            <td><?= h($error['message']) ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
