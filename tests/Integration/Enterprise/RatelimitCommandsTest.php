<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class RatelimitCommandsTest extends IntegrationTestCase
{
    public function testCreateCheckPeekResetStatsDrop(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('ratelimit');
            self::assertSame('OK', $this->client->ratelimit()->create($name, 2, 60_000));

            $peek = $this->client->ratelimit()->peek($name, 'user1');
            self::assertSame(2, $peek['remaining']);

            $first = $this->client->ratelimit()->check($name, 'user1');
            self::assertSame(1, $first['allowed']);
            $second = $this->client->ratelimit()->check($name, 'user1');
            self::assertSame(1, $second['allowed']);
            $third = $this->client->ratelimit()->check($name, 'user1');
            self::assertSame(0, $third['allowed']);

            self::assertSame('OK', $this->client->ratelimit()->reset($name, 'user1'));
            $afterReset = $this->client->ratelimit()->peek($name, 'user1');
            self::assertSame(2, $afterReset['remaining']);

            $stats = $this->client->ratelimit()->stats($name);
            self::assertSame($name, $stats['name']);
            self::assertSame('sliding_window', $stats['algo']);

            self::assertContains($name, $this->client->ratelimit()->list());
            self::assertSame('OK', $this->client->ratelimit()->drop($name));
        });
    }

    public function testTokenBucketWithRefill(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('ratelimit-tb');
            self::assertSame('OK', $this->client->ratelimit()->create($name, 10, 1000, algo: 'token_bucket', refill: 5.0));
            $check = $this->client->ratelimit()->check($name, 'k', cost: 3);
            self::assertSame(1, $check['allowed']);
            $this->client->ratelimit()->drop($name);
        });
    }

    public function testResetOfUnknownLimiterErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->ratelimit()->reset($this->ns('missing'), 'k');
    }
}
