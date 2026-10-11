<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit;

use HotKV\LlmPrice;
use HotKV\Support\Reply;
use PHPUnit\Framework\TestCase;

final class LlmPriceTest extends TestCase
{
    /** @return list<string|int> */
    private static function fields(): array
    {
        return [
            'model', 'claude-sonnet-5', 'source', 'custom', 'priced', 1, 'retired', 0,
            'input_usd_per_mtok', '3.000000', 'output_usd_per_mtok', '15.000000',
            'cache_read_usd_per_mtok', '0.300000', 'cache_write_5m_usd_per_mtok', '3.750000',
            'cache_write_1h_usd_per_mtok', '6.000000',
        ];
    }

    public function testParsesResp2FlatArrayByFieldName(): void
    {
        $price = LlmPrice::fromMap(Reply::toMap(self::fields()));
        self::assertSame('claude-sonnet-5', $price->model);
        self::assertSame('custom', $price->source);
        self::assertTrue($price->priced);
        self::assertFalse($price->retired);
        self::assertEqualsWithDelta(3.0, $price->inputUsdPerMtok, 1e-9);
        self::assertEqualsWithDelta(15.0, $price->outputUsdPerMtok, 1e-9);
        self::assertEqualsWithDelta(0.3, $price->cacheReadUsdPerMtok, 1e-9);
        self::assertEqualsWithDelta(3.75, $price->cacheWrite5mUsdPerMtok, 1e-9);
        self::assertEqualsWithDelta(6.0, $price->cacheWrite1hUsdPerMtok, 1e-9);
    }

    public function testParsesResp3MapReplyIdentically(): void
    {
        $flat = self::fields();
        $mapReply = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $mapReply[(string) $flat[$i]] = $flat[$i + 1];
        }
        self::assertEquals(
            LlmPrice::fromMap(Reply::toMap($flat)),
            LlmPrice::fromMap(Reply::toMap($mapReply)),
        );
    }

    public function testUnpricedModelDefaults(): void
    {
        $fields = [
            'model', 'unknown-model', 'source', 'unpriced', 'priced', 0, 'retired', 0,
            'input_usd_per_mtok', '0.000000', 'output_usd_per_mtok', '0.000000',
            'cache_read_usd_per_mtok', '0.000000', 'cache_write_5m_usd_per_mtok', '0.000000',
            'cache_write_1h_usd_per_mtok', '0.000000',
        ];
        $price = LlmPrice::fromMap(Reply::toMap($fields));
        self::assertSame('unpriced', $price->source);
        self::assertFalse($price->priced);
        self::assertEqualsWithDelta(0.0, $price->inputUsdPerMtok, 1e-9);
    }

    public function testMissingFieldsDefault(): void
    {
        $price = LlmPrice::fromMap(['model' => 'm']);
        self::assertSame('m', $price->model);
        self::assertSame('', $price->source);
        self::assertFalse($price->priced);
        self::assertFalse($price->retired);
        self::assertEqualsWithDelta(0.0, $price->inputUsdPerMtok, 1e-9);
    }

    public function testUnknownExtraFieldIsIgnoredNotRaised(): void
    {
        $price = LlmPrice::fromMap(Reply::toMap(array_merge(self::fields(), ['brand_new_field', 'x'])));
        self::assertSame('claude-sonnet-5', $price->model);
    }

    public function testFormatPricePassesStringsThroughUnchanged(): void
    {
        self::assertSame('15.000001', LlmPrice::formatPrice('15.000001'));
    }

    public function testFormatPriceFormatsIntsAndFloatsWithoutExponentOrTrailingZeros(): void
    {
        self::assertSame('3', LlmPrice::formatPrice(3));
        self::assertSame('0.3', LlmPrice::formatPrice(0.30));
        self::assertSame('15.000001', LlmPrice::formatPrice(15.000001));
        self::assertSame('0', LlmPrice::formatPrice(0));
        self::assertSame('100', LlmPrice::formatPrice(100));
    }
}
