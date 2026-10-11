<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Enterprise;

use HotKV\Enterprise\ScacheCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

final class ScacheCommandsTest extends TestCase
{
    public function testSetWithoutUsageSendsNoUsageGroup(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new ScacheCommands($executor))->set('ns', 'q', 'r', [0.1, 0.2]);
        self::assertSame(['SCACHE.SET', 'ns', 'q', 'r', '0.1', '0.2'], $executor->lastCall());
    }

    public function testSetWithTtlAndUsage(): void
    {
        $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20, cacheReadTokens: 5,
            cacheWrite5mTokens: 7, cacheWrite1hTokens: 9);
        $executor = new RecordingExecutor(reply: 'OK');
        (new ScacheCommands($executor))->set('ns', 'q', 'r', [0.1, 0.2], ttl: 60, usage: $usage);
        self::assertSame(
            ['SCACHE.SET', 'ns', 'q', 'r', '0.1', '0.2', 'TTL', 60, 'MODEL', 'claude-sonnet-5', 'INPUT', 100,
                'OUTPUT', 20, 'CACHEREAD', 5, 'CACHEWRITE5M', 7, 'CACHEWRITE1H', 9],
            $executor->lastCall(),
        );
    }

    public function testStatsParsesUsageSavedFields(): void
    {
        $reply = [
            'entries', 3, 'hits', 2, 'misses', 1, 'hit_rate', '0.666667', 'p50_us', 120,
            'usage_saved_requests', 2, 'usage_saved_input_tokens', 200, 'usage_saved_output_tokens', 40,
            'usage_saved_cache_read_tokens', 10, 'usage_saved_cache_write_tokens', 5,
            'usage_saved_cost_usd', '0.000160',
        ];
        $executor = new RecordingExecutor(reply: $reply);
        $stats = (new ScacheCommands($executor))->stats('ns');
        self::assertSame('0.666667', $stats['hit_rate']);
        self::assertSame('0.000160', $stats['usage_saved_cost_usd']);
        self::assertSame(200, $stats['usage_saved_input_tokens']);
    }
}
