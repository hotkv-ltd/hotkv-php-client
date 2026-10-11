<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class BitmapCommandsTest extends IntegrationTestCase
{
    public function testSetbitGetbit(): void
    {
        $k = $this->key();
        self::assertSame(0, $this->client->setbit($k, 7, 1));
        self::assertSame(1, $this->client->getbit($k, 7));
        self::assertSame(0, $this->client->getbit($k, 6));
    }

    public function testBitcount(): void
    {
        $k = $this->key();
        $this->client->set($k, 'foobar');
        self::assertSame(26, $this->client->bitcount($k));
        self::assertSame(4, $this->client->bitcount($k, 0, 0));
        self::assertSame(17, $this->client->bitcount($k, 5, 30, bit: true));
    }

    public function testBitpos(): void
    {
        $k = $this->key();
        $this->client->set($k, "\xff\xf0\x00");
        self::assertSame(12, $this->client->bitpos($k, 0));
    }

    public function testBitop(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $dest = $this->key();
        $this->client->set($k1, 'abc');
        $this->client->set($k2, 'abd');
        self::assertSame(3, $this->client->bitop('AND', $dest, [$k1, $k2]));
    }

    public function testBitfield(): void
    {
        $k = $this->key();
        $result = $this->client->bitfield($k, ['SET', 'u8', 0, 255, 'GET', 'u8', 0]);
        self::assertSame([0, 255], $result);

        $ro = $this->client->bitfieldRo($k, ['GET', 'u8', 0]);
        self::assertSame([255], $ro);
    }
}
