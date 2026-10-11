<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

/**
 * Live tests for the typed methods added after the first release: the
 * multi-key pops, LCS, SORT, the newer string and hash commands,
 * EVAL_RO and server introspection.
 */
final class ExtendedCommandsTest extends IntegrationTestCase
{
    public function testLmpopAndBlmpop(): void
    {
        $a = $this->key('a');
        $b = $this->key('b');
        $this->client->rpush($b, ['x', 'y', 'z']);
        self::assertSame([$b, ['x', 'y']], $this->client->lmpop([$a, $b], true, 2));
        self::assertSame([$b, ['z']], $this->client->lmpop([$a, $b], false));
        self::assertNull($this->client->lmpop([$a, $b]));
        $this->client->rpush($a, ['p', 'q']);
        self::assertSame([$a, ['q']], $this->client->blmpop(1, [$a], false));
        self::assertNull($this->client->blmpop(0.05, [$b]));
    }

    public function testZmpopAndBzmpop(): void
    {
        $a = $this->key('a');
        $b = $this->key('b');
        $this->client->zadd($b, ['m1' => 1.0, 'm2' => 2.0, 'm3' => 3.0]);
        $low = $this->client->zmpop([$a, $b]);
        self::assertSame($b, $low[0]);
        self::assertSame('m1', $low[1][0][0]);
        $this->assertScore(1.0, $low[1][0][1]);
        $high = $this->client->zmpop([$b], true, 5);
        self::assertSame(['m3', 'm2'], array_column($high[1], 0));
        self::assertNull($this->client->zmpop([$a, $b]));
        $this->client->zadd($a, ['only' => 7.0]);
        $got = $this->client->bzmpop(1, [$a], true);
        self::assertSame($a, $got[0]);
        self::assertSame('only', $got[1][0][0]);
        self::assertNull($this->client->bzmpop(0.05, [$a]));
    }

    public function testLcs(): void
    {
        $a = $this->key('a');
        $b = $this->key('b');
        $this->client->mset([$a => 'ohmytext', $b => 'mynewtext']);
        self::assertSame('mytext', $this->client->lcs($a, $b));
        self::assertSame(6, $this->client->lcs($a, $b, len: true));
        $idx = $this->client->lcs($a, $b, idx: true, minMatchLen: 4, withMatchLen: true);
        self::assertSame(6, $idx['len']);
        self::assertCount(1, $idx['matches']);
        self::assertSame(4, $idx['matches'][0][2]);
    }

    public function testSortAndSortRo(): void
    {
        $k = $this->key();
        $this->client->rpush($k, ['3', '1', '2', '10']);
        self::assertSame(['1', '2', '3', '10'], $this->client->sort($k));
        self::assertSame(['10', '3', '2', '1'], $this->client->sort($k, desc: true));
        self::assertSame(['1', '10', '2', '3'], $this->client->sortRo($k, alpha: true));
        self::assertSame(['2', '3'], $this->client->sortRo($k, limitOffset: 1, limitCount: 2));
        $dest = $this->key('dest');
        self::assertSame(4, $this->client->sort($k, store: $dest));
        self::assertSame(['1', '2', '3', '10'], $this->client->lrange($dest, 0, -1));

        $w = $this->key('w');
        $this->client->hset($w . ':1', ['name' => 'one']);
        $this->client->hset($w . ':2', ['name' => 'two']);
        $this->client->rpush($w, ['2', '1']);
        self::assertSame(['one', 'two'], $this->client->sort($w, get: [$w . ':*->name']));
        $this->client->del([$w . ':1', $w . ':2']);
    }

    public function testDigestAndDelex(): void
    {
        $k = $this->key();
        self::assertNull($this->client->digest($k));
        $this->client->set($k, 'v1');
        $digest = $this->client->digest($k);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $digest);
        self::assertSame(0, $this->client->delex($k, ifeq: 'other'));
        self::assertSame(0, $this->client->delex($k, ifdeq: '0000000000000000'));
        self::assertSame(1, $this->client->delex($k, ifdeq: $digest));
        $this->client->set($k, 'v2');
        self::assertSame(1, $this->client->delex($k, ifne: 'v1'));
        $this->client->set($k, 'v3');
        self::assertSame(1, $this->client->delex($k));
        self::assertSame(0, $this->client->exists([$k]));
    }

    public function testMsetex(): void
    {
        $a = $this->key('a');
        $b = $this->key('b');
        self::assertSame(1, $this->client->msetex([$a => '1', $b => '2'], ex: 100));
        self::assertSame(['1', '2'], $this->client->mget([$a, $b]));
        self::assertGreaterThan(0, $this->client->ttl($a));
        self::assertLessThanOrEqual(100, $this->client->ttl($b));
        self::assertSame(0, $this->client->msetex([$a => 'x', $this->key('c') => 'y'], nx: true, ex: 100));
        self::assertSame('1', $this->client->get($a));
        self::assertSame(1, $this->client->msetex([$a => 'xx'], xx: true, px: 50_000));
        self::assertSame('xx', $this->client->get($a));
    }

    public function testHgetdelHgetexHsetex(): void
    {
        $k = $this->key();
        $this->client->hset($k, ['f1' => 'v1', 'f2' => 'v2']);
        self::assertSame(['v1', null], $this->client->hgetdel($k, ['f1', 'nope']));
        self::assertSame(['f2' => 'v2'], $this->client->hgetall($k));

        self::assertSame(1, $this->client->hsetex($k, ['f3' => 'v3', 'f4' => 'v4'], ex: 100));
        self::assertSame(0, $this->client->hsetex($k, ['f3' => 'again'], fnx: true, ex: 100));
        self::assertSame('v3', $this->client->hget($k, 'f3'));
        $ttl = $this->client->httl($k, ['f3', 'f2']);
        self::assertGreaterThan(0, $ttl[0]);
        self::assertSame(-1, $ttl[1]);

        self::assertSame(['v3', null], $this->client->hgetex($k, ['f3', 'zz'], persist: true));
        self::assertSame([-1], $this->client->httl($k, ['f3']));
        self::assertSame(['v4'], $this->client->hgetex($k, ['f4'], ex: 50));
        self::assertGreaterThan(0, $this->client->httl($k, ['f4'])[0]);
    }

    public function testEvalRoAndEvalshaRo(): void
    {
        $k = $this->key();
        $this->client->set($k, 'hello');
        $script = "return redis.call('GET', KEYS[1]) .. ARGV[1]";
        self::assertSame('hello!', $this->client->evalRo($script, [$k], ['!']));
        $sha = $this->client->scriptLoad($script);
        self::assertSame('hello?', $this->client->evalshaRo($sha, [$k], ['?']));
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->evalRo("return redis.call('SET', KEYS[1], 'x')", [$k]);
    }

    public function testRoleWaitAndMemoryUsage(): void
    {
        $role = $this->client->role();
        self::assertSame('master', $role[0]);
        $k = $this->key();
        $this->client->set($k, str_repeat('x', 1000));
        self::assertSame(0, $this->client->wait(0, 10));
        self::assertGreaterThanOrEqual(1000, $this->client->memoryUsage($k));
        self::assertNull($this->client->memoryUsage($this->key()));
    }

    public function testSlowlog(): void
    {
        self::assertIsInt($this->client->slowlogLen());
        self::assertIsArray($this->client->slowlogGet(5));
        self::assertSame('OK', $this->client->slowlogReset());
        self::assertSame(0, $this->client->slowlogLen());
    }
}
