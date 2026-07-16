<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAuditLogs extends BaseMigration
{
    public function change(): void
    {
        $table = $this->table('audit_logs', [
            'id' => false,
            'primary_key' => ['id'],
            'comment' => '監査ログ',
        ]);

        $table
            ->addColumn('id', 'biginteger', [
                'identity' => true,
                'signed' => false,
                'comment' => '主キー',
            ])
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => '実行ユーザー(null=未認証)',
            ])
            ->addColumn('category', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => '分類(大枠。Behavior時はモデル名、Component時は指定値)',
            ])
            ->addColumn('action', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'アクション種別(詳細。create/update/delete/login/logout等)',
            ])
            ->addColumn('target_id', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => '対象レコードのID',
            ])
            ->addColumn('ip_address', 'string', [
                'limit' => 45,
                'null' => true,
                'comment' => 'リクエスト元IP',
            ])
            ->addColumn('user_agent', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'UserAgent(IPと組み合わせたアクセス調査のため独立カラム)',
            ])
            ->addColumn('context', 'json', [
                'null' => true,
                'comment' => 'コンテキスト情報(Behavior時: before/after、Component時: その他任意情報)',
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
                'comment' => '作成日時',
            ])
            ->addIndex(['category', 'action'], [
                'name' => 'idx_audit_logs_category_action',
            ])
            ->addIndex(['category', 'target_id'], [
                'name' => 'idx_audit_logs_category_target',
            ])
            ->addIndex(['user_id', 'created'], [
                'name' => 'idx_audit_logs_user_created',
            ])
            ->addIndex(['ip_address', 'user_agent'], [
                'name' => 'idx_audit_logs_ip_ua',
            ])
            ->create();
    }
}
