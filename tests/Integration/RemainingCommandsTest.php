<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Exception\ServerException;

/**
 * Live tests for typed core methods that no other integration test
 * covers: the sorted set range removals, blocking list/zset variants,
 * key introspection, publish, setnx, db switching and flushing.
 */
final class RemainingCommandsTest extends IntegrationTestCase
{
    public function testZremrangebyrank(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0, 'e' => 5.0]);
        self::assertSame(2, $this->client->zremrangebyrank($k, 0, 1));
        self::assertSame(['c', 'd', 'e'], $this->client->zrange($k, '0', '-1'));
        self::assertSame(1, $this->client->zremrangebyrank($k, -1, -1));
        self::assertSame(['c', 'd'], $this->client->zrange($k, '0', '-1'));
        self::assertSame(0, $this->client->zremrangebyrank($k, 10, 20));
        self::assertSame(2, $this->client->zcard($k));
    }

    public function testZremrangebyscoreHonoursExclusiveAndInfiniteBounds(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0, 'e' => 5.0]);
        self::assertSame(1, $this->client->zremrangebyscore($k, '(1', '2'));
        self::assertSame(['a', 'c', 'd', 'e'], $this->client->zrange($k, '0', '-1'));
        self::assertSame(2, $this->client->zremrangebyscore($k, '4', '+inf'));
        self::assertSame(['a', 'c'], $this->client->zrange($k, '0', '-1'));
        self::assertSame(2, $this->client->zremrangebyscore($k, '-inf', '+inf'));
        self::assertSame(0, $this->client->exists([$k]));
    }

    public function testZremrangebylex(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 0.0, 'b' => 0.0, 'c' => 0.0, 'd' => 0.0, 'e' => 0.0]);
        self::assertSame(2, $this->client->zremrangebylex($k, '[b', '(d'));
        self::assertSame(['a', 'd', 'e'], $this->client->zrange($k, '0', '-1'));
        self::assertSame(1, $this->client->zremrangebylex($k, '(d', '+'));
        self::assertSame(['a', 'd'], $this->client->zrange($k, '0', '-1'));
    }

    public function testZremrangeOnMissingKeyAndWrongType(): void
    {
        $missing = $this->key();
        self::assertSame(0, $this->client->zremrangebyrank($missing, 0, -1));
        self::assertSame(0, $this->client->zremrangebyscore($missing, '-inf', '+inf'));
        self::assertSame(0, $this->client->zremrangebylex($missing, '-', '+'));

        $str = $this->key();
        $this->client->set($str, 'x');
        $this->expectException(\HotKV\Exception\WrongTypeException::class);
        $this->client->zremrangebyrank($str, 0, -1);
    }

    public function testZremrangeRejectsNonNumericScoreBound(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0]);
        $this->expectException(ServerException::class);
        $this->client->zremrangebyscore($k, 'abc', '5');
    }

    public function testZremrangeInPipelineAndTransaction(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        $results = $this->client->pipeline(function ($p) use ($k) {
            $p->zremrangebyrank($k, 0, 0);
            $p->zremrangebyscore($k, '3', '3');
            $p->zrange($k, '0', '-1');
        });
        self::assertSame([1, 1, ['b']], $results);

        $tx = $this->client->transaction(function ($t) use ($k) {
            $t->zremrangebylex($k, '-', '+');
        });
        self::assertSame([1], $tx);
    }

    public function testBzpopmax(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0]);
        $popped = $this->client->bzpopmax([$k], 1);
        self::assertSame($k, $popped[0]);
        self::assertSame('b', $popped[1]);
        $this->assertScore(2.0, $popped[2]);
        self::assertNull($this->client->bzpopmax([$this->key()], 0.05));
    }

    public function testBlmoveAndBrpoplpush(): void
    {
        $src = $this->key('src');
        $dst = $this->key('dst');
        $this->client->rpush($src, ['a', 'b', 'c']);
        self::assertSame('a', $this->client->blmove($src, $dst, true, false, 1));
        self::assertSame('c', $this->client->blmove($src, $dst, false, true, 1));
        self::assertSame(['c', 'a'], $this->client->lrange($dst, 0, -1));
        self::assertSame(['b'], $this->client->lrange($src, 0, -1));

        self::assertSame('b', $this->client->brpoplpush($src, $dst, 1));
        self::assertSame(['b', 'c', 'a'], $this->client->lrange($dst, 0, -1));
        self::assertNull($this->client->brpoplpush($src, $dst, 0.05));
        self::assertNull($this->client->blmove($src, $dst, true, true, 0.05));
    }

    public function testSetnx(): void
    {
        $k = $this->key();
        self::assertSame(1, $this->client->setnx($k, 'first'));
        self::assertSame(0, $this->client->setnx($k, 'second'));
        self::assertSame('first', $this->client->get($k));
    }

    public function testHpttl(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['f' => '1', 'g' => '2']);
        self::assertSame([1], $this->client->hexpire($k, 100, ['f']));
        $ttl = $this->client->hpttl($k, ['f', 'g', 'missing']);
        self::assertCount(3, $ttl);
        self::assertGreaterThan(0, $ttl[0]);
        self::assertLessThanOrEqual(100_000, $ttl[0]);
        self::assertSame(-1, $ttl[1]);
        self::assertSame(-2, $ttl[2]);
    }

    public function testObjectRefcountIdletimeAndFreq(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        self::assertSame(1, $this->client->objectRefcount($k));
        $idle = $this->client->objectIdletime($k);
        self::assertIsInt($idle);
        self::assertGreaterThanOrEqual(0, $idle);
        try {
            $freq = $this->client->objectFreq($k);
            self::assertIsInt($freq);
        } catch (ServerException $e) {
            // Standard semantics: OBJECT FREQ needs an LFU eviction policy.
            self::assertStringContainsString('LFU', $e->getMessage());
        }
        self::assertNull($this->client->objectRefcount($this->key()));
    }

    public function testMoveAndSelect(): void
    {
        $k = $this->key();
        $this->client->set($k, 'moved');
        try {
            self::assertSame(1, $this->client->move($k, 9));
            self::assertNull($this->client->get($k));
            self::assertSame('OK', $this->client->select(9));
            self::assertSame('moved', $this->client->get($k));
            self::assertSame(1, $this->client->del([$k]));
        } finally {
            $this->client->select(0);
        }
        // Moving onto a key that already exists in the target DB moves nothing.
        $this->client->set($k, 'a');
        $this->client->select(9);
        $this->client->set($k, 'b');
        $this->client->select(0);
        self::assertSame(0, $this->client->move($k, 9));
        $this->client->select(9);
        self::assertSame('b', $this->client->get($k));
        $this->client->del([$k]);
        $this->client->select(0);
    }

    public function testFlushdbOnlyClearsTheSelectedDatabase(): void
    {
        $k = $this->key();
        $this->client->set($k, 'stay');
        try {
            $this->client->select(11);
            $this->client->set($k, 'go');
            self::assertSame('OK', $this->client->flushdb());
            self::assertSame(0, $this->client->dbsize());
            $this->client->set($k, 'go');
            self::assertSame('OK', $this->client->flushdb(true));
        } finally {
            $this->client->select(0);
        }
        self::assertSame('stay', $this->client->get($k));
    }

    public function testFlushall(): void
    {
        if (getenv('HOTKV_TEST_ALLOW_FLUSHALL') !== '1') {
            self::markTestSkipped('FLUSHALL wipes every database; set HOTKV_TEST_ALLOW_FLUSHALL=1 to run it against a throwaway server.');
        }
        $this->client->set($this->key(), 'x');
        try {
            $this->client->select(12);
            $this->client->set($this->key(), 'y');
            $this->client->select(0);
            self::assertSame('OK', $this->client->flushall());
            self::assertSame(0, $this->client->dbsize());
            $this->client->select(12);
            self::assertSame(0, $this->client->dbsize());
            $this->client->set($this->key(), 'z');
            $this->client->select(0);
            self::assertSame('OK', $this->client->flushall(true));
            self::assertSame(0, $this->client->dbsize());
        } finally {
            $this->client->select(0);
        }
    }

    public function testLastsave(): void
    {
        $t = $this->client->lastsave();
        self::assertIsInt($t);
        self::assertGreaterThan(1_600_000_000, $t);
        self::assertLessThanOrEqual(time() + 5, $t);
    }

    public function testPublishWithoutSubscribersAndShardNumsub(): void
    {
        $channel = 'hotkv-php-test-pub-' . bin2hex(random_bytes(4));
        self::assertSame(0, $this->client->publish($channel, 'hello'));
        self::assertSame(0, $this->client->spublish($channel, 'hello'));
        self::assertSame([$channel, 0], $this->client->pubsubShardNumsub([$channel]));
    }

    public function testXgroupSetId(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['f' => '1']);
        $this->client->xadd($k, ['f' => '2']);
        self::assertSame('OK', $this->client->xgroupCreate($k, 'grp', '0'));
        self::assertSame('OK', $this->client->xgroupSetId($k, 'grp', '$'));
        // After moving the group's last-delivered id to the end nothing is pending delivery.
        $read = $this->client->xreadgroup('grp', 'c1', [$k => '>'], count: 10);
        self::assertTrue($read === null || $read === [] || $read === [$k => []], 'group should have no undelivered entries');
        self::assertSame('OK', $this->client->xgroupSetId($k, 'grp', '0'));
        $read = $this->client->xreadgroup('grp', 'c1', [$k => '>'], count: 10);
        self::assertNotEmpty($read);
    }
}
