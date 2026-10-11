<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Enterprise\KeyedCacheCommands;
use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

/**
 * TOOL.CACHE.*, NCACHE.*, GUARD.*, RERANK.*: identical engines, each
 * gated on its own license feature. Each needs a server license that
 * includes that feature.
 */
final class KeyedCacheCommandsTest extends IntegrationTestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function families(): iterable
    {
        yield 'toolCache' => ['toolCache'];
        yield 'ncache' => ['ncache'];
        yield 'guard' => ['guard'];
        yield 'rerank' => ['rerank'];
    }

    /** @dataProvider families */
    public function testLifecycle(string $accessor): void
    {
        $this->withLicense(function () use ($accessor): void {
            /** @var KeyedCacheCommands $cache */
            $cache = $this->client->{$accessor}();
            $ns = $this->ns($accessor);

            self::assertSame('OK', $cache->create($ns, maxEntries: 500, ttl: 0));
            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            self::assertSame('OK', $cache->set($ns, 'key1', 'value1', usage: $usage));
            self::assertSame('value1', $cache->get($ns, 'key1')); // attribute the hit
            self::assertNull($cache->get($ns, 'missing'));

            $stats = $cache->stats($ns);
            self::assertSame(1, $stats['entries']);
            self::assertGreaterThanOrEqual(1, $stats['usage_saved_requests']);
            self::assertArrayHasKey('usage_saved_cost_usd', $stats);

            self::assertContains($ns, $cache->list());
            self::assertSame(1, $cache->del($ns, 'key1'));
            self::assertGreaterThanOrEqual(0, $cache->flush($ns));
            self::assertSame(1, $cache->drop($ns));
        });
    }
}
