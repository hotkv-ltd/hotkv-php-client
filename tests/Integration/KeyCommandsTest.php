<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class KeyCommandsTest extends IntegrationTestCase
{
    public function testDelUnlinkExistsTouch(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->set($k1, 'a');
        $this->client->set($k2, 'b');

        self::assertSame(2, $this->client->exists([$k1, $k2, $this->key()]));
        self::assertSame(1, $this->client->touch([$k1]));
        self::assertSame(1, $this->client->del([$k1]));
        self::assertSame(1, $this->client->unlink([$k2]));
        self::assertSame(0, $this->client->exists([$k1, $k2]));
    }

    public function testType(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertSame('string', $this->client->type($k));
        self::assertSame('none', $this->client->type($this->key()));
    }

    public function testExpiryFamily(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertSame(1, $this->client->expire($k, 100));
        self::assertGreaterThan(0, $this->client->ttl($k));
        self::assertGreaterThan(0, $this->client->pttl($k));
        self::assertGreaterThan(time(), $this->client->expiretime($k));
        self::assertGreaterThan(time() * 1000, $this->client->pexpiretime($k));
        self::assertSame(1, $this->client->persist($k));
        self::assertSame(-1, $this->client->ttl($k));

        self::assertSame(1, $this->client->pexpire($k, 100000));
        self::assertSame(1, $this->client->expireat($k, time() + 100));
        self::assertSame(1, $this->client->pexpireat($k, (time() + 100) * 1000));
    }

    public function testExpireConditionalFlags(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertSame(1, $this->client->expire($k, 100));
        // NX: only set a TTL when none exists yet.
        self::assertSame(0, $this->client->expire($k, 200, nx: true));
        // GT: only replace with a greater TTL.
        self::assertSame(1, $this->client->expire($k, 500, gt: true));
        self::assertSame(0, $this->client->expire($k, 100, gt: true));
    }

    public function testTtlAndPttlSentinels(): void
    {
        $missing = $this->key();
        self::assertSame(-2, $this->client->ttl($missing));
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertSame(-1, $this->client->ttl($k));
    }

    public function testRenameAndRenamenx(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $k3 = $this->key();
        $this->client->set($k1, 'v1');
        $this->client->set($k2, 'v2');

        self::assertSame('OK', $this->client->rename($k1, $k3));
        self::assertSame('v1', $this->client->get($k3));

        self::assertSame(0, $this->client->renamenx($k2, $k3));
        self::assertSame(1, $this->client->renamenx($k2, $this->key()));
    }

    public function testCopy(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->set($k1, 'v');
        self::assertSame(1, $this->client->copy($k1, $k2));
        self::assertSame('v', $this->client->get($k2));
        self::assertSame(0, $this->client->copy($k1, $k2));
        self::assertSame(1, $this->client->copy($k1, $k2, replace: true));
    }

    public function testRandomkeyAndKeys(): void
    {
        $k = $this->key('randomkey-probe');
        $this->client->set($k, 'v');
        self::assertNotNull($this->client->randomkey());
        self::assertContains($k, $this->client->keys($k));
    }

    public function testDumpAndRestore(): void
    {
        $k = $this->key();
        $k2 = $this->key();
        $this->client->set($k, 'dump-me');
        $dump = $this->client->dump($k);
        self::assertIsString($dump);
        self::assertSame('OK', $this->client->restore($k2, 0, $dump));
        self::assertSame('dump-me', $this->client->get($k2));
    }

    public function testObjectEncoding(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertIsString($this->client->objectEncoding($k));
    }

    public function testNanosecondTtlFamily(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');

        self::assertSame(-1, $this->client->nttl($k));
        self::assertSame(1, $this->client->nexpire($k, 60_000_000_000));
        $remaining = $this->client->nttl($k);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60_000_000_000, $remaining);

        $future = (int) ((microtime(true) + 120) * 1_000_000_000);
        self::assertSame(1, $this->client->nexpireat($k, $future));
        self::assertGreaterThan(0, $this->client->nttl($k));

        self::assertSame(0, $this->client->nexpire($this->key(), 1_000_000_000));
        self::assertSame(-2, $this->client->nttl($this->key()));
    }
}
