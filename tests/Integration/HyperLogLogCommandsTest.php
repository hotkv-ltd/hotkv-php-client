<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class HyperLogLogCommandsTest extends IntegrationTestCase
{
    public function testAddCountAndMerge(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $dest = $this->key();

        self::assertSame(1, $this->client->pfadd($k1, ['a', 'b', 'c']));
        self::assertSame(1, $this->client->pfadd($k2, ['c', 'd', 'e']));

        self::assertSame(3, $this->client->pfcount([$k1]));

        self::assertSame('OK', $this->client->pfmerge($dest, [$k1, $k2]));
        self::assertSame(5, $this->client->pfcount([$dest]));
        self::assertSame(5, $this->client->pfcount([$k1, $k2]));
    }
}
