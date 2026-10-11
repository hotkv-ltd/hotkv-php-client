<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Cluster;

use HotKV\Cluster\Crc16;
use PHPUnit\Framework\TestCase;

final class Crc16Test extends TestCase
{
    public function testRawChecksumMatchesXmodemVector(): void
    {
        self::assertSame(0x31C3, Crc16::checksum('123456789'));
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function keySlotVectors(): iterable
    {
        yield 'foo' => ['foo', 12182];
        yield 'bar' => ['bar', 5061];
        yield 'hello' => ['hello', 866];
        yield '{user1000}.following' => ['{user1000}.following', 3443];
        yield '{user1000}.followers' => ['{user1000}.followers', 3443];
        yield 'empty tag falls back to whole key' => ['{}foo', 9500];
        yield 'tag ends at first closing brace' => ['foo{}{bar}', 8363];
        yield 'tag may include a literal open brace' => ['foo{{bar}}zap', 4015];
        yield 'only the first tag is used' => ['foo{bar}{zap}', 5061];
    }

    /** @dataProvider keySlotVectors */
    public function testKeySlotMatchesClusterKeyslot(string $key, int $expectedSlot): void
    {
        self::assertSame($expectedSlot, Crc16::keySlot($key));
    }

    public function testHashTagsThatShareATagRouteToTheSameSlot(): void
    {
        self::assertSame(Crc16::keySlot('{user1000}.following'), Crc16::keySlot('{user1000}.followers'));
    }

    public function testSlotIsAlwaysInRange(): void
    {
        foreach (['a', 'b', str_repeat('x', 500), '', '{}', '{a}{b}{c}'] as $key) {
            $slot = Crc16::keySlot($key);
            self::assertGreaterThanOrEqual(0, $slot);
            self::assertLessThan(16384, $slot);
        }
    }
}
