<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class HashCommandsTest extends IntegrationTestCase
{
    public function testSetGetAndGetall(): void
    {
        $k = $this->key();
        self::assertSame(2, $this->client->hset($k, ['a' => '1', 'b' => '2']));
        self::assertSame('1', $this->client->hget($k, 'a'));
        $all = $this->client->hgetall($k);
        ksort($all);
        self::assertSame(['a' => '1', 'b' => '2'], $all);
    }

    public function testHsetnx(): void
    {
        $k = $this->key();
        self::assertSame(1, $this->client->hsetnx($k, 'a', '1'));
        self::assertSame(0, $this->client->hsetnx($k, 'a', '2'));
        self::assertSame('1', $this->client->hget($k, 'a'));
    }

    public function testHmgetAndHmset(): void
    {
        $k = $this->key();
        self::assertSame('OK', $this->client->hmset($k, ['a' => '1', 'b' => '2']));
        self::assertSame(['1', '2', null], $this->client->hmget($k, ['a', 'b', 'c']));
    }

    public function testHdelHlenHexists(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['a' => '1', 'b' => '2', 'c' => '3']);
        self::assertSame(3, $this->client->hlen($k));
        self::assertSame(1, $this->client->hexists($k, 'a'));
        self::assertSame(1, $this->client->hdel($k, ['a']));
        self::assertSame(0, $this->client->hexists($k, 'a'));
        self::assertSame(2, $this->client->hlen($k));
    }

    public function testHkeysHvals(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['a' => '1', 'b' => '2']);
        self::assertEqualsCanonicalizing(['a', 'b'], $this->client->hkeys($k));
        self::assertEqualsCanonicalizing(['1', '2'], $this->client->hvals($k));
    }

    public function testHincrbyAndHincrbyfloat(): void
    {
        $k = $this->key();
        self::assertSame(5, $this->client->hincrby($k, 'n', 5));
        self::assertSame('5.5', $this->client->hincrbyfloat($k, 'n', 0.5));
    }

    public function testHstrlen(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['a' => 'hello']);
        self::assertSame(5, $this->client->hstrlen($k, 'a'));
        self::assertSame(0, $this->client->hstrlen($k, 'missing'));
    }

    public function testHrandfield(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['a' => '1', 'b' => '2']);
        self::assertIsString($this->client->hrandfield($k));
        $fields = $this->client->hrandfield($k, 10);
        self::assertLessThanOrEqual(2, count($fields));
        $withValues = $this->client->hrandfield($k, 10, withValues: true);
        self::assertLessThanOrEqual(2, count($withValues));
        foreach ($withValues as $pair) {
            self::assertSame($pair[0] === 'a' ? '1' : '2', $pair[1]);
        }
    }

    public function testFieldExpiryFamily(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['a' => '1', 'b' => '2']);

        $ttls = $this->client->httl($k, ['a', 'missing']);
        self::assertSame([-1, -2], $ttls);

        $setResult = $this->client->hexpire($k, 100, ['a']);
        self::assertSame([1], $setResult);

        $ttlAfter = $this->client->httl($k, ['a']);
        self::assertGreaterThan(0, $ttlAfter[0]);
        self::assertLessThanOrEqual(100, $ttlAfter[0]);

        self::assertSame([1], $this->client->hpersist($k, ['a']));
        self::assertSame([-1], $this->client->httl($k, ['a']));

        self::assertSame([1], $this->client->hpexpire($k, 100_000, ['a']));
        self::assertSame([1], $this->client->hexpireat($k, time() + 100, ['b']));
        self::assertSame([1], $this->client->hpexpireat($k, (time() + 100) * 1000, ['b']));

        $expireTimes = $this->client->hexpiretime($k, ['a']);
        self::assertGreaterThan(time(), $expireTimes[0]);
        $pExpireTimes = $this->client->hpexpiretime($k, ['a']);
        self::assertGreaterThan(time() * 1000, $pExpireTimes[0]);
    }
}
