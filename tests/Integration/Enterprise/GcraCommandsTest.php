<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class GcraCommandsTest extends IntegrationTestCase
{
    public function testLimitAllowsThenDenies(): void
    {
        $k = $this->ns('gcra');

        $first = $this->client->gcra()->limit($k, 60_000, 2);
        self::assertSame(0, $first['denied']);
        self::assertSame(2, $first['limit']);
        self::assertSame(-1, $first['retry_after_ms']);

        $second = $this->client->gcra()->limit($k, 60_000, 2);
        self::assertSame(0, $second['denied']);

        $third = $this->client->gcra()->limit($k, 60_000, 2);
        self::assertSame(1, $third['denied']);
        self::assertGreaterThanOrEqual(0, $third['retry_after_ms']);

        self::assertSame(1, $this->client->gcra()->reset([$k]));
    }

    public function testResetOfUnknownKeyReturnsZero(): void
    {
        self::assertSame(0, $this->client->gcra()->reset([$this->ns('missing')]));
    }
}
