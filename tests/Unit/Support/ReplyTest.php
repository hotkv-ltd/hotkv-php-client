<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Support;

use HotKV\Support\Reply;
use PHPUnit\Framework\TestCase;

final class ReplyTest extends TestCase
{
    public function testToMapFlattensResp2StyleAlternatingList(): void
    {
        self::assertSame(['a' => '1', 'b' => '2'], Reply::toMap(['a', '1', 'b', '2']));
    }

    public function testToMapPassesThroughAlreadyAssociativeArray(): void
    {
        $map = ['a' => 1, 'b' => 2];
        self::assertSame($map, Reply::toMap($map));
    }

    public function testToMapHandlesNullAndEmpty(): void
    {
        self::assertSame([], Reply::toMap(null));
        self::assertSame([], Reply::toMap([]));
    }

    public function testPairsToMapConvertsNestedTuples(): void
    {
        self::assertSame(['x' => 1, 'y' => 2], Reply::pairsToMap([['x', 1], ['y', 2]]));
    }

    public function testToPairMapHandlesResp2FlatList(): void
    {
        self::assertSame(['m1' => '1', 'm2' => '2'], Reply::toPairMap(['m1', '1', 'm2', '2']));
    }

    public function testToPairMapHandlesResp3ArrayOfPairs(): void
    {
        self::assertSame(['m1' => '1', 'm2' => '2'], Reply::toPairMap([['m1', '1'], ['m2', '2']]));
    }

    public function testToPairMapHandlesTrueResp3Map(): void
    {
        $map = ['m1' => '1', 'm2' => '2'];
        self::assertSame($map, Reply::toPairMap($map));
    }

    public function testToPairMapHandlesEmpty(): void
    {
        self::assertSame([], Reply::toPairMap([]));
        self::assertSame([], Reply::toPairMap(null));
    }

    public function testChunksSplitsFlatListIntoRecords(): void
    {
        self::assertSame([['k1', 'd1'], ['k2', 'd2']], Reply::chunks(['k1', 'd1', 'k2', 'd2'], 2));
    }

    public function testToBool(): void
    {
        self::assertTrue(Reply::toBool(1));
        self::assertFalse(Reply::toBool(0));
    }
}
