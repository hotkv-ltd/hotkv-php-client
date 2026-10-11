<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class SortedSetCommandsTest extends IntegrationTestCase
{
    public function testAddScoreAndCard(): void
    {
        $k = $this->key();
        self::assertSame(3, $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]));
        self::assertSame(3, $this->client->zcard($k));
        $this->assertScore(2.0, $this->client->zscore($k, 'b'));
        $scores = $this->client->zmscore($k, ['a', 'x', 'c']);
        $this->assertScore(1.0, $scores[0]);
        self::assertNull($scores[1]);
        $this->assertScore(3.0, $scores[2]);
    }

    public function testAddFlagsNxXxGtLtCh(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 5.0]);
        self::assertSame(0, $this->client->zadd($k, ['a' => 10.0], nx: true));
        $this->assertScore(5.0, $this->client->zscore($k, 'a'));

        self::assertSame(0, $this->client->zadd($k, ['a' => 1.0], gt: true));
        $this->assertScore(5.0, $this->client->zscore($k, 'a'));

        self::assertSame(1, $this->client->zadd($k, ['a' => 20.0], gt: true, ch: true));
        $this->assertScore(20.0, $this->client->zscore($k, 'a'));
    }

    public function testIncrbyAndAddIncrOption(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0]);
        $this->assertScore(3.5, $this->client->zincrby($k, 2.5, 'a'));
        $this->assertScore(4.5, $this->client->zadd($k, ['a' => 1.0], incr: true));
    }

    public function testRemCountLexcount(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        self::assertSame(1, $this->client->zrem($k, ['a']));
        self::assertSame(2, $this->client->zcount($k, '-inf', '+inf'));

        $lex = $this->key();
        $this->client->zadd($lex, ['a' => 0.0, 'b' => 0.0, 'c' => 0.0]);
        self::assertSame(3, $this->client->zlexcount($lex, '-', '+'));
        self::assertSame(2, $this->client->zlexcount($lex, '[a', '[b'));
    }

    public function testZrangeUnifiedFormWithScores(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);

        self::assertSame(['a', 'b', 'c'], $this->client->zrange($k, '0', '-1'));
        $this->assertScoreMap(['a' => 1.0, 'b' => 2.0, 'c' => 3.0], $this->client->zrange($k, '0', '-1', withScores: true));
        self::assertSame(['c', 'b', 'a'], $this->client->zrange($k, '0', '-1', rev: true));
        self::assertSame(['b', 'c'], $this->client->zrange($k, '2', '3', byScore: true));
        self::assertSame(['c', 'b'], $this->client->zrange($k, '+inf', '-inf', byScore: true, rev: true, limitOffset: 0, limitCount: 2));
        self::assertSame(['a', 'b'], $this->client->zrange($k, '-', '[b', byLex: true));
    }

    public function testZrangebyscoreFamily(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);

        self::assertSame(['a', 'b'], $this->client->zrangebyscore($k, '1', '2'));
        $this->assertScoreMap(['b' => 2.0, 'c' => 3.0], $this->client->zrangebyscore($k, '2', '3', withScores: true));
        self::assertSame(['c', 'b'], $this->client->zrevrangebyscore($k, '3', '1', limitOffset: 0, limitCount: 2));
    }

    public function testZrangebylexFamily(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 0.0, 'b' => 0.0, 'c' => 0.0]);
        self::assertSame(['a', 'b'], $this->client->zrangebylex($k, '-', '[b'));
        self::assertSame(['c', 'b'], $this->client->zrevrangebylex($k, '+', '[b'));
    }

    public function testZrevrangeWithScores(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0]);
        $this->assertScoreMap(['a' => 1.0, 'b' => 2.0], $this->client->zrevrange($k, 0, -1, withScores: true));
    }

    public function testZrangestore(): void
    {
        $k = $this->key();
        $dest = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        self::assertSame(3, $this->client->zrangestore($dest, $k, '0', '-1'));
        self::assertSame(['a', 'b', 'c'], $this->client->zrange($dest, '0', '-1'));
    }

    public function testZrangestoreByscoreHonorsFilter(): void
    {
        $k = $this->key();
        $dest = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0]);
        self::assertSame(2, $this->client->zrangestore($dest, $k, '2', '3', byScore: true));
        self::assertSame(['b', 'c'], $this->client->zrange($dest, '0', '-1'));

        self::assertSame(2, $this->client->zrangestore($dest, $k, '4', '1', byScore: true, rev: true, limitOffset: 0, limitCount: 2));
        self::assertSame(['c', 'd'], $this->client->zrange($dest, '0', '-1'));
    }

    public function testZrangestoreBylexHonorsFilter(): void
    {
        $k = $this->key();
        $dest = $this->key();
        $this->client->zadd($k, ['a' => 0.0, 'b' => 0.0, 'c' => 0.0]);
        self::assertSame(3, $this->client->zrangestore($dest, $k, '-', '+', byLex: true));
        self::assertSame(['a', 'b', 'c'], $this->client->zrange($dest, '0', '-1'));
    }

    public function testRankAndRevrank(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        self::assertSame(0, $this->client->zrank($k, 'a'));
        self::assertSame(2, $this->client->zrevrank($k, 'a'));
        self::assertNull($this->client->zrank($k, 'missing'));
        $withScore = $this->client->zrank($k, 'a', withScore: true);
        self::assertSame(0, $withScore[0]);
        $this->assertScore(1.0, $withScore[1]);
    }

    public function testPopMinMax(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        $min = $this->client->zpopmin($k);
        self::assertSame('a', $min[0]);
        $this->assertScore(1.0, $min[1]);
        $max = $this->client->zpopmax($k);
        self::assertSame('c', $max[0]);
        $this->assertScore(3.0, $max[1]);
    }

    public function testBlockingPopReturnsImmediatelyWhenDataPresent(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0]);
        $popped = $this->client->bzpopmin([$k], 1);
        self::assertSame([$k, 'a'], [$popped[0], $popped[1]]);
        $this->assertScore(1.0, $popped[2]);
    }

    public function testZrandmember(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0]);
        self::assertIsString($this->client->zrandmember($k));
        self::assertLessThanOrEqual(2, count($this->client->zrandmember($k, 10)));

        $withScores = $this->client->zrandmember($k, 2, withScores: true);
        self::assertCount(2, $withScores);
        foreach ($withScores as $pair) {
            $this->assertScore($pair[0] === 'a' ? 1.0 : 2.0, $pair[1]);
        }
    }

    public function testZpopminZpopmaxWithCount(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0]);
        $mins = $this->client->zpopmin($k, 2);
        self::assertSame(['a', 'b'], [$mins[0][0], $mins[1][0]]);
        $this->assertScore(1.0, $mins[0][1]);
        $this->assertScore(2.0, $mins[1][1]);

        $maxes = $this->client->zpopmax($k, 2);
        self::assertSame(['d', 'c'], [$maxes[0][0], $maxes[1][0]]);
        $this->assertScore(4.0, $maxes[0][1]);
        $this->assertScore(3.0, $maxes[1][1]);
    }

    public function testDiffInterUnionAndStores(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->zadd($k1, ['a' => 1.0, 'b' => 2.0]);
        $this->client->zadd($k2, ['b' => 10.0, 'c' => 3.0]);

        self::assertSame(['a'], $this->client->zdiff([$k1, $k2]));
        $this->assertScoreMap(['a' => 1.0], $this->client->zdiff([$k1, $k2], withScores: true));
        self::assertSame(1, $this->client->zdiffstore($this->key(), [$k1, $k2]));

        self::assertSame(['b'], $this->client->zinter([$k1, $k2]));
        self::assertSame(1, $this->client->zintercard([$k1, $k2]));

        $union = $this->client->zunion([$k1, $k2], withScores: true);
        $this->assertScore(12.0, $union['b']);

        $dest = $this->key();
        self::assertSame(3, $this->client->zunionstore($dest, [$k1, $k2]));
        self::assertSame(1, $this->client->zinterstore($this->key(), [$k1, $k2], weights: [1.0, 2.0], aggregate: 'max'));
    }
}
