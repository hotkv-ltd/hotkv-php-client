<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Enterprise;

use HotKV\Enterprise\LlmBudgetCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

final class LlmBudgetCommandsTest extends TestCase
{
    public function testConsumeLegacyPositional(): void
    {
        $executor = new RecordingExecutor(reply: 1);
        (new LlmBudgetCommands($executor))->consume('b', 100, 0.01);
        self::assertSame(['LLMBUDGET.CONSUME', 'b', 100, '0.01'], $executor->lastCall());
    }

    public function testConsumeWithUsageSendsZeroPositionalTokensAndCost(): void
    {
        $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20, cacheReadTokens: 5,
            cacheWrite5mTokens: 7, cacheWrite1hTokens: 9);
        $executor = new RecordingExecutor(reply: 1);
        (new LlmBudgetCommands($executor))->consume('b', usage: $usage);
        self::assertSame(
            ['LLMBUDGET.CONSUME', 'b', 0, '0.0', 'MODEL', 'claude-sonnet-5', 'INPUT', 100, 'OUTPUT', 20,
                'CACHEREAD', 5, 'CACHEWRITE5M', 7, 'CACHEWRITE1H', 9],
            $executor->lastCall(),
        );
    }

    public function testStatusParsesUnpricedRequests(): void
    {
        $reply = [
            'tokens_used', 100, 'token_limit', 1000, 'cost_used_usd', '0.01', 'cost_limit_usd', '5.0',
            'window_seconds', 3600, 'window_remaining', 1800, 'requests_allowed', 1,
            'requests_rejected', 0, 'unpriced_requests', 0,
        ];
        $executor = new RecordingExecutor(reply: $reply);
        $status = (new LlmBudgetCommands($executor))->status('b');
        self::assertSame(0, $status['unpriced_requests']);
    }
}
