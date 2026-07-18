<?php

/**
 * CakeUtility Plugin - Default Configuration
 *
 * このファイルはプラグインのデフォルト設定を定義します。
 * アプリケーション側で `config/cake_utility.php` を作成すると上書きされます。
 *
 * Usage:
 * ```
 * Configure::load('CakeUtility.cake_utility', 'default');
 * ```
 *
 * @see \CakeUtility\CakeUtilityPlugin::bootstrap()
 */

return [
    'AuditLog' => [
        // デフォルト保持日数（未指定のカテゴリー/アクションに適用）
        'retentionDays' => 90,

        // カテゴリー＋アクションごとの個別保持日数設定
        'retentionByCategory' => [
            'auth' => [
                'login' => 30,
                'logout' => 30,
                'failed' => 30,
            ],
        ],

        // CSV出力先ディレクトリ
        'csvExportPath' => LOGS . 'audit' . DS,

        // CSVファイルのデフォルト保持日数
        'csvRetentionDays' => 365,

        // カテゴリーごとのCSV保持日数設定
        'csvRetentionByCategory' => [
            'auth' => [
                '__default__' => 180,
            ],
        ],
    ],

    'Htmx' => [
        // HTMXリクエスト時にレイアウトを自動無効化する
        'disableAutoLayout' => true,
    ],

    'I18n' => [
        // デフォルトロケール
        'defaultLocale' => 'ja_JP',
        // サポートするロケール一覧
        'supportedLocales' => ['ja_JP', 'en_US'],
    ],

    'Scenario' => [
        // シナリオファイルのベースディレクトリ（ScenarioLoader のデフォルトパス）
        'baseDir' => 'config/Seeds/data',
    ],
];
