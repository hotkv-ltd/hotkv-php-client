<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Enterprise;

use HotKV\Enterprise\PcacheCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

final class PcacheCommandsTest extends TestCase
{
    private function usage(): TokenUsage
    {
        return new TokenUsage(
            model: 'claude-sonnet-5',
            inputTokens: 100,
            outputTokens: 20,
            cacheReadTokens: 5,
            cacheWrite5mTokens: 7,
            cacheWrite1hTokens: 9,
        );
    }

    public function testSetWithoutUsageSendsNoUsageGroup(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new PcacheCommands($executor))->set('ns', 'p', 'r');
        self::assertSame(['PCACHE.SET', 'ns', 'p', 'r'], $executor->lastCall());
    }

    public function testSetWithTtlMetaAndUsage(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new PcacheCommands($executor))->set('ns', 'p', 'r', ttl: 60, meta: '{}', usage: $this->usage());
        self::assertSame(
            ['PCACHE.SET', 'ns', 'p', 'r', 'TTL', 60, 'META', '{}', 'MODEL', 'claude-sonnet-5', 'INPUT', 100,
                'OUTPUT', 20, 'CACHEREAD', 5, 'CACHEWRITE5M', 7, 'CACHEWRITE1H', 9],
            $executor->lastCall(),
        );
    }

    public function testSetWithOnlyAUsageModelOmitsZeroCounts(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new PcacheCommands($executor))->set('ns', 'p', 'r', usage: new TokenUsage(model: 'm'));
        self::assertSame(['PCACHE.SET', 'ns', 'p', 'r', 'MODEL', 'm'], $executor->lastCall());
    }

    public function testStatsParsesUsageSavedCostUsdAsString(): void
    {
        $reply = [
            'entries', 1, 'hits', 0, 'misses', 1, 'evictions', 0, 'memory_bytes', 100,
            'hit_rate', '0.0', 'p50_ns', 1, 'p95_ns', 2, 'p99_ns', 3, 'cost_saved_usd', '0.000000',
            'usage_saved_requests', 0, 'usage_saved_input_tokens', 0, 'usage_saved_output_tokens', 0,
            'usage_saved_cache_read_tokens', 0, 'usage_saved_cache_write_tokens', 0,
            'usage_saved_cost_usd', '0.000000',
        ];
        $executor = new RecordingExecutor(reply: $reply);
        $stats = (new PcacheCommands($executor))->stats('ns');
        self::assertSame('0.000000', $stats['usage_saved_cost_usd']);
    }
}
