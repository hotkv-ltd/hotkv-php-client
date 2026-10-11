<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Enterprise;

use HotKV\LlmPrice;
use HotKV\Enterprise\LlmStatsCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use PHPUnit\Framework\TestCase;

final class LlmStatsCommandsTest extends TestCase
{
    public function testRecordLegacyPositionalOnly(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->record('b', 100, 20, 0, 0.01, 200);
        self::assertSame(['LLMSTATS.RECORD', 'b', 100, 20, 0, '0.01', 200], $executor->lastCall());
    }

    public function testRecordWithErrorFlag(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->record('b', 100, 20, 0, 0.01, 200, error: true);
        self::assertSame(['LLMSTATS.RECORD', 'b', 100, 20, 0, '0.01', 200, 'ERROR', 1], $executor->lastCall());
    }

    public function testRecordWithModelAndCacheWriteOptions(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->record(
            'b',
            100,
            20,
            0,
            0,
            200,
            error: false,
            model: 'claude-sonnet-5',
            cacheWrite5mTokens: 30,
            cacheWrite1hTokens: 40,
        );
        self::assertSame(
            ['LLMSTATS.RECORD', 'b', 100, 20, 0, '0.0', 200, 'ERROR', 0, 'MODEL', 'claude-sonnet-5',
                'CACHEWRITE5M', 30, 'CACHEWRITE1H', 40],
            $executor->lastCall(),
        );
    }

    public function testGetParsesNewFields(): void
    {
        $reply = [
            'requests', 1, 'errors', 0, 'input_tokens', 100, 'output_tokens', 20, 'cached_tokens', 0,
            'cost_usd', '0.002', 'avg_latency_ms', '200.0', 'requests_1m', 1, 'tokens_1m', 120,
            'cost_usd_1m', '0.002', 'cache_write_5m_tokens', 30, 'cache_write_1h_tokens', 40,
            'unpriced_requests', 0,
        ];
        $executor = new RecordingExecutor(reply: $reply);
        $stats = (new LlmStatsCommands($executor))->get('b');
        self::assertSame(30, $stats['cache_write_5m_tokens']);
        self::assertSame(40, $stats['cache_write_1h_tokens']);
        self::assertSame(0, $stats['unpriced_requests']);
    }

    public function testPriceSetRequiredFieldsOnly(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->priceSet('m', 3, 15);
        self::assertSame(['LLMSTATS.PRICE', 'SET', 'm', 'INPUT', '3', 'OUTPUT', '15'], $executor->lastCall());
    }

    public function testPriceSetWithOptionalCachePrices(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->priceSet('m', 3, 15, cacheReadUsdPerMtok: 0.3, cacheWrite5mUsdPerMtok: 3.75, cacheWrite1hUsdPerMtok: 6);
        self::assertSame(
            ['LLMSTATS.PRICE', 'SET', 'm', 'INPUT', '3', 'OUTPUT', '15', 'CACHEREAD', '0.3', 'CACHEWRITE5M', '3.75', 'CACHEWRITE1H', '6'],
            $executor->lastCall(),
        );
    }

    public function testPriceSetAcceptsDecimalStringsUnchanged(): void
    {
        $executor = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($executor))->priceSet('m', '3', '15.000001');
        self::assertSame(['LLMSTATS.PRICE', 'SET', 'm', 'INPUT', '3', 'OUTPUT', '15.000001'], $executor->lastCall());
    }

    public function testPriceGetArgumentList(): void
    {
        $reply = [
            'model', 'm', 'source', 'unpriced', 'priced', 0, 'retired', 0, 'input_usd_per_mtok', '0.000000',
            'output_usd_per_mtok', '0.000000', 'cache_read_usd_per_mtok', '0.000000',
            'cache_write_5m_usd_per_mtok', '0.000000', 'cache_write_1h_usd_per_mtok', '0.000000',
        ];
        $executor = new RecordingExecutor(reply: $reply);
        (new LlmStatsCommands($executor))->priceGet('m');
        self::assertSame(['LLMSTATS.PRICE', 'GET', 'm'], $executor->lastCall());
    }

    public function testPriceListParsesArrayOfEntries(): void
    {
        $entry = [
            'model', 'm', 'source', 'default', 'priced', 1, 'retired', 0, 'input_usd_per_mtok', '3.000000',
            'output_usd_per_mtok', '15.000000', 'cache_read_usd_per_mtok', '0.300000',
            'cache_write_5m_usd_per_mtok', '3.750000', 'cache_write_1h_usd_per_mtok', '6.000000',
        ];
        $executor = new RecordingExecutor(reply: [$entry, $entry]);
        $list = (new LlmStatsCommands($executor))->priceList();
        self::assertSame(['LLMSTATS.PRICE', 'LIST'], $executor->lastCall());
        self::assertCount(2, $list);
        self::assertInstanceOf(LlmPrice::class, $list[0]);
        self::assertSame('m', $list[0]->model);
    }

    public function testPriceDelArgumentListAndBoolReply(): void
    {
        $executor = new RecordingExecutor(reply: 1);
        $result = (new LlmStatsCommands($executor))->priceDel('m');
        self::assertSame(['LLMSTATS.PRICE', 'DEL', 'm'], $executor->lastCall());
        self::assertSame(1, $result);
    }
}
