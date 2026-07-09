<?php

declare(strict_types=1);

namespace CakeUtility\Test\TestCase\Yaml;

use Cake\TestSuite\TestCase;
use CakeUtility\Yaml\Loader;
use Cake\Database\Schema\TableSchema;
use RuntimeException;

class LoaderTest extends TestCase
{
    protected $Loader;

    public function setUp(): void
    {
        parent::setUp();
        $this->Loader = new Loader();
    }

    /**
     * 基本的なパースとコントロールキーの除外を検証します。
     */
    public function testParseAndResolveBasic(): void
    {
        $yaml = <<<YAML
- _table: users
  _ref: user_1
  username: test_user
  _keys: username
  email: test@example.com
YAML;
        $data = $this->Loader->parse($yaml);
        $this->assertCount(1, $data);
        $this->assertEquals('users', $data[0]['_table']);

        // resolve後、アンダースコアで始まるキーが除外されること
        $resolved = $this->Loader->resolve($data[0], [], null);
        $this->assertArrayNotHasKey('_table', $resolved);
        $this->assertArrayNotHasKey('_ref', $resolved);
        $this->assertArrayNotHasKey('_keys', $resolved);
        $this->assertEquals('test_user', $resolved['username']);
        $this->assertEquals('test@example.com', $resolved['email']);
    }

    /**
     * @now, @today の解決を検証します。
     */
    public function testResolveDatetime(): void
    {
        $yaml = <<<YAML
- created: '@now'
  updated: '@today'
YAML;
        $data = $this->Loader->parse($yaml);
        $this->assertCount(1, $data);

        $this->Loader->setTodayBoundary('start');
        $resolved = $this->Loader->resolve($data[0], [], null);

        $this->assertInstanceOf(\DateTimeImmutable::class, $resolved['created']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $resolved['updated']);

        // @today が今日の 00:00:00 になっているか
        $today = (new \DateTimeImmutable())->setTime(0, 0, 0);
        $this->assertEquals($today->format('Y-m-d H:i:s'), $resolved['updated']->format('Y-m-d H:i:s'));
    }

    /**
     * setTodayBoundary による @today の挙動変更を検証します。
     */
    public function testTodayBoundary(): void
    {
        // 終了時刻に設定
        $this->Loader->setTodayBoundary('end');
        $yamlEnd = <<<YAML
- target: '@today'
YAML;
        $dataEnd = $this->Loader->parse($yamlEnd);
        $resolvedEnd = $this->Loader->resolve($dataEnd[0], [], null);
        $todayEnd = (new \DateTimeImmutable())->setTime(23, 59, 59);
        $this->assertEquals($todayEnd->format('Y-m-d H:i:s'), $resolvedEnd['target']->format('Y-m-d H:i:s'));

        // 開始時刻に設定
        $this->Loader->setTodayBoundary('start');
        $yamlStart = <<<YAML
- target: '@today'
YAML;
        $dataStart = $this->Loader->parse($yamlStart);
        $resolvedStart = $this->Loader->resolve($dataStart[0], [], null);
        $todayStart = (new \DateTimeImmutable())->setTime(0, 0, 0);
        $this->assertEquals($todayStart->format('Y-m-d H:i:s'), $resolvedStart['target']->format('Y-m-d H:i:s'));
    }

    /**
     * ref:label による参照解決を検証します。
     */
    public function testResolveReference(): void
    {
        $refMap = ['user_1' => 123];
        $data = [
            'user_id' => 'ref:user_1',
            'name' => 'Taro',
        ];

        $resolved = $this->Loader->resolve($data, $refMap, null);
        $this->assertEquals(123, $resolved['user_id']);
    }

    /**
     * 未定義の参照がある場合に例外がスローされることを検証します。
     */
    public function testResolveReferenceNotFound(): void
    {
        $refMap = [];
        $data = ['user_id' => 'ref:unknown'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reference "unknown" not found');

        $this->Loader->resolve($data, $refMap, null);
    }

    /**
     * スキーマに基づいた型キャストを検証します。
     */
    public function testResolveWithSchema(): void
    {
        $schema = new TableSchema('test_table');
        $schema->addColumn('id', ['type' => 'integer']);
        $schema->addColumn('is_active', ['type' => 'boolean']);
        $schema->addColumn('meta', ['type' => 'json']);
        $schema->addColumn('created', ['type' => 'datetime']);

        $data = [
            'id' => '100',
            'is_active' => '1',
            'meta' => '{"role": "admin"}',
            'created' => '2026-01-01 10:00:00',
        ];

        $resolved = $this->Loader->resolve($data, [], $schema);

        $this->assertSame(100, $resolved['id']);
        $this->assertSame(true, $resolved['is_active']);
        $this->assertIsArray($resolved['meta']);
        $this->assertEquals('admin', $resolved['meta']['role']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $resolved['created']);
    }
}
