<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class ServerCommandsTest extends IntegrationTestCase
{
    public function testPingAndEcho(): void
    {
        self::assertSame('PONG', $this->client->ping());
        self::assertSame('hi', $this->client->ping('hi'));
        self::assertSame('echoed', $this->client->echo('echoed'));
    }

    public function testInfoAndDbsize(): void
    {
        self::assertIsString($this->client->info());
        self::assertIsInt($this->client->dbsize());
    }

    public function testTime(): void
    {
        $time = $this->client->time();
        self::assertCount(2, $time);
        self::assertGreaterThan(1_700_000_000, (int) $time[0]);
    }

    public function testConfigGetAndSet(): void
    {
        $before = $this->client->configGet('maxmemory');
        self::assertArrayHasKey('maxmemory', $before);

        self::assertSame('OK', $this->client->configSet(['maxmemory' => $before['maxmemory']]));
    }

    public function testClientSetnameGetnameId(): void
    {
        self::assertSame('OK', $this->client->clientSetname('hotkv-php-test'));
        self::assertSame('hotkv-php-test', $this->client->clientGetname());
        self::assertIsInt($this->client->clientId());
    }

    public function testSwapdb(): void
    {
        self::assertSame('OK', $this->client->swapdb(0, 0));
    }
}
