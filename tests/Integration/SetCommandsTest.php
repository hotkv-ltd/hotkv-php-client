<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class SetCommandsTest extends IntegrationTestCase
{
    public function testAddRemMembersCard(): void
    {
        $k = $this->key();
        self::assertSame(3, $this->client->sadd($k, ['a', 'b', 'c']));
        self::assertSame(1, $this->client->sadd($k, ['a', 'd']));
        self::assertSame(4, $this->client->scard($k));
        self::assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $this->client->smembers($k));
        self::assertSame(1, $this->client->srem($k, ['a']));
    }

    public function testIsmemberAndSmismember(): void
    {
        $k = $this->key();
        $this->client->sadd($k, ['a', 'b']);
        self::assertSame(1, $this->client->sismember($k, 'a'));
        self::assertSame(0, $this->client->sismember($k, 'z'));
        self::assertSame([1, 0, 1], $this->client->smismember($k, ['a', 'z', 'b']));
    }

    public function testPopAndRandmember(): void
    {
        $k = $this->key();
        $this->client->sadd($k, ['a', 'b', 'c']);
        $popped = $this->client->spop($k);
        self::assertContains($popped, ['a', 'b', 'c']);
        self::assertSame(2, $this->client->scard($k));

        $rand = $this->client->srandmember($k);
        self::assertIsString($rand);

        $many = $this->client->srandmember($k, 10);
        self::assertLessThanOrEqual(2, count($many));
    }

    public function testSmove(): void
    {
        $src = $this->key();
        $dst = $this->key();
        $this->client->sadd($src, ['a', 'b']);
        self::assertSame(1, $this->client->smove($src, $dst, 'a'));
        self::assertSame(0, $this->client->sismember($src, 'a'));
        self::assertSame(1, $this->client->sismember($dst, 'a'));
    }

    public function testInterUnionDiffAndStores(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->sadd($k1, ['a', 'b', 'c']);
        $this->client->sadd($k2, ['b', 'c', 'd']);

        self::assertEqualsCanonicalizing(['b', 'c'], $this->client->sinter([$k1, $k2]));
        self::assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $this->client->sunion([$k1, $k2]));
        self::assertEqualsCanonicalizing(['a'], $this->client->sdiff([$k1, $k2]));

        $dest = $this->key();
        self::assertSame(2, $this->client->sinterstore($dest, [$k1, $k2]));
        self::assertSame(4, $this->client->sunionstore($dest, [$k1, $k2]));
        self::assertSame(1, $this->client->sdiffstore($dest, [$k1, $k2]));
    }

    public function testSintercard(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->sadd($k1, ['a', 'b', 'c']);
        $this->client->sadd($k2, ['b', 'c', 'd']);
        self::assertSame(2, $this->client->sintercard([$k1, $k2]));
        self::assertSame(1, $this->client->sintercard([$k1, $k2], limit: 1));
    }
}
