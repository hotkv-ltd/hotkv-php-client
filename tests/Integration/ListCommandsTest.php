<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class ListCommandsTest extends IntegrationTestCase
{
    public function testPushPopAndLen(): void
    {
        $k = $this->key();
        self::assertSame(2, $this->client->rpush($k, ['a', 'b']));
        self::assertSame(3, $this->client->lpush($k, ['z']));
        self::assertSame(3, $this->client->llen($k));
        self::assertSame(['z', 'a', 'b'], $this->client->lrange($k, 0, -1));

        self::assertSame('z', $this->client->lpop($k));
        self::assertSame('b', $this->client->rpop($k));
    }

    public function testPopWithCount(): void
    {
        $k = $this->key();
        $this->client->rpush($k, ['a', 'b', 'c']);
        self::assertSame(['a', 'b'], $this->client->lpop($k, 2));
    }

    public function testPushxOnMissingKey(): void
    {
        $k = $this->key();
        self::assertSame(0, $this->client->lpushx($k, ['a']));
        self::assertSame(0, $this->client->rpushx($k, ['a']));
    }

    public function testIndexSetInsertRemTrim(): void
    {
        $k = $this->key();
        $this->client->rpush($k, ['a', 'b', 'c']);
        self::assertSame('b', $this->client->lindex($k, 1));
        self::assertSame('OK', $this->client->lset($k, 1, 'B'));
        self::assertSame('B', $this->client->lindex($k, 1));

        self::assertSame(4, $this->client->linsert($k, true, 'B', 'A2'));
        self::assertSame(['a', 'A2', 'B', 'c'], $this->client->lrange($k, 0, -1));

        self::assertSame(1, $this->client->lrem($k, 1, 'A2'));
        self::assertSame('OK', $this->client->ltrim($k, 0, 1));
        self::assertSame(['a', 'B'], $this->client->lrange($k, 0, -1));
    }

    public function testRpoplpushAndLmove(): void
    {
        $src = $this->key();
        $dst = $this->key();
        $this->client->rpush($src, ['a', 'b', 'c']);

        self::assertSame('c', $this->client->rpoplpush($src, $dst));
        self::assertSame(['c'], $this->client->lrange($dst, 0, -1));

        self::assertSame('a', $this->client->lmove($src, $dst, true, false));
        self::assertSame(['c', 'a'], $this->client->lrange($dst, 0, -1));
    }

    public function testLpos(): void
    {
        $k = $this->key();
        $this->client->rpush($k, ['a', 'b', 'c', 'b']);
        self::assertSame(1, $this->client->lpos($k, 'b'));
        self::assertSame([1, 3], $this->client->lpos($k, 'b', count: 0));
    }

    public function testBlockingPopReturnsImmediatelyWhenDataPresent(): void
    {
        $k = $this->key();
        $this->client->rpush($k, ['only']);
        self::assertSame([$k, 'only'], $this->client->blpop([$k], 1));
    }

    public function testBlockingPopTimesOutOnEmptyKey(): void
    {
        $k = $this->key();
        self::assertNull($this->client->brpop([$k], 1));
    }
}
