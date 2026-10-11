<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\LlmPrice;
use HotKV\Tests\Integration\IntegrationTestCase;

/** LLMSTATS.*: needs a server license that includes the llm-stats feature. */
final class LlmStatsCommandsTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $this->withLicense(function (): void {
            $bucket = $this->ns('llmstats');
            self::assertSame('OK', $this->client->llmstats()->bucket($bucket));
            self::assertSame('OK', $this->client->llmstats()->record($bucket, 100, 50, 0, 0.002, 250));
            self::assertSame('OK', $this->client->llmstats()->record($bucket, 10, 5, 0, 0.0002, 40, error: true));

            $stats = $this->client->llmstats()->get($bucket);
            self::assertSame(2, $stats['requests']);
            self::assertSame(1, $stats['errors']);
            self::assertSame(110, $stats['input_tokens']);
            self::assertSame(0, $stats['unpriced_requests']);

            self::assertContains($bucket, $this->client->llmstats()->list());
            self::assertSame(1, $this->client->llmstats()->reset($bucket));
            self::assertSame(1, $this->client->llmstats()->drop($bucket));
        });
    }

    public function testRecordWithModelPricesFromTheServerTable(): void
    {
        $this->withLicense(function (): void {
            $bucket = $this->ns('llmstats-model');
            self::assertSame('OK', $this->client->llmstats()->bucket($bucket));
            self::assertSame('OK', $this->client->llmstats()->record(
                $bucket,
                100,
                20,
                0,
                0,
                150,
                model: 'claude-sonnet-5',
                cacheWrite5mTokens: 10,
            ));

            $stats = $this->client->llmstats()->get($bucket);
            self::assertSame(1, $stats['requests']);
            self::assertGreaterThan(0.0, (float) $stats['cost_usd']);
            self::assertSame(10, $stats['cache_write_5m_tokens']);
            self::assertSame(0, $stats['unpriced_requests']);

            self::assertSame('OK', $this->client->llmstats()->record($bucket, 5, 5, 0, 0, 10, model: 'totally-unknown-model-xyz'));
            $unpricedStats = $this->client->llmstats()->get($bucket);
            self::assertGreaterThanOrEqual(1, $unpricedStats['unpriced_requests']);
        });
    }

    public function testPriceSetGetListDel(): void
    {
        $this->withLicense(function (): void {
            $model = $this->ns('model');
            self::assertSame('OK', $this->client->llmstats()->priceSet($model, 3, 15, cacheReadUsdPerMtok: 0.3));

            $price = $this->client->llmstats()->priceGet($model);
            self::assertInstanceOf(LlmPrice::class, $price);
            self::assertSame($model, $price->model);
            self::assertSame('custom', $price->source);
            self::assertTrue($price->priced);
            self::assertEqualsWithDelta(3.0, $price->inputUsdPerMtok, 1e-9);
            self::assertEqualsWithDelta(15.0, $price->outputUsdPerMtok, 1e-9);
            self::assertEqualsWithDelta(0.3, $price->cacheReadUsdPerMtok, 1e-9);
            // Undeclared cache write tiers default to 1.25x/2x the input price.
            self::assertEqualsWithDelta(3.75, $price->cacheWrite5mUsdPerMtok, 1e-9);
            self::assertEqualsWithDelta(6.0, $price->cacheWrite1hUsdPerMtok, 1e-9);

            $list = $this->client->llmstats()->priceList();
            self::assertContains($model, array_map(static fn (LlmPrice $p): string => $p->model, $list));

            $unknown = $this->client->llmstats()->priceGet($this->ns('no-such-model'));
            self::assertSame('unpriced', $unknown->source);
            self::assertFalse($unknown->priced);

            self::assertSame(1, $this->client->llmstats()->priceDel($model));
            self::assertSame(0, $this->client->llmstats()->priceDel($model)); // already gone
        });
    }
}
