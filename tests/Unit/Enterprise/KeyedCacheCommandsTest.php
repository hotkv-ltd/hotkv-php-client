<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Enterprise;

use HotKV\Enterprise\KeyedCacheCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

final class KeyedCacheCommandsTest extends TestCase
{
    public function testSetWithUsage(): void
    {
        $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20, cacheReadTokens: 5,
            cacheWrite5mTokens: 7, cacheWrite1hTokens: 9);
        $executor = new RecordingExecutor(reply: 'OK');
        (new KeyedCacheCommands($executor, 'TOOL.CACHE'))->set('ns', 'k', 'v', usage: $usage);
        self::assertSame(
            ['TOOL.CACHE.SET', 'ns', 'k', 'v', 'MODEL', 'claude-sonnet-5', 'INPUT', 100, 'OUTPUT', 20,
                'CACHEREAD', 5, 'CACHEWRITE5M', 7, 'CACHEWRITE1H', 9],
            $executor->lastCall(),
        );
    }

    public function testSetTtlMetaNoUsage(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new KeyedCacheCommands($executor, 'GUARD'))->set('ns', 'k', 'v', ttl: 30, meta: 'm');
        self::assertSame(['GUARD.SET', 'ns', 'k', 'v', 'TTL', 30, 'META', 'm'], $executor->lastCall());
    }

    public function testStatsParsesUsageSavedCostUsd(): void
    {
        $reply = [
            'entries', 1, 'hits', 1, 'misses', 0, 'evictions', 0, 'memory_bytes', 10,
            'usage_saved_requests', 1, 'usage_saved_input_tokens', 10, 'usage_saved_output_tokens', 2,
            'usage_saved_cache_read_tokens', 0, 'usage_saved_cache_write_tokens', 0,
            'usage_saved_cost_usd', '0.000030',
        ];
        $executor = new RecordingExecutor(reply: $reply);
        $stats = (new KeyedCacheCommands($executor, 'NCACHE'))->stats('ns');
        self::assertSame('0.000030', $stats['usage_saved_cost_usd']);
    }
}
