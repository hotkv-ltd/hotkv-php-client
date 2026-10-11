<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit;

use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

final class TokenUsageTest extends TestCase
{
    public function testModelOnlySendsJustModel(): void
    {
        $usage = new TokenUsage(model: 'claude-sonnet-5');
        self::assertSame(['MODEL', 'claude-sonnet-5'], $usage->toArgs());
    }

    public function testZeroCountsAreOmittedFromTheWire(): void
    {
        $usage = new TokenUsage(
            model: 'claude-sonnet-5',
            inputTokens: 0,
            outputTokens: 0,
            cacheReadTokens: 0,
            cacheWrite5mTokens: 0,
            cacheWrite1hTokens: 0,
        );
        self::assertSame(['MODEL', 'claude-sonnet-5'], $usage->toArgs());
    }

    public function testNonZeroCountsAreIncludedInOrder(): void
    {
        $usage = new TokenUsage(
            model: 'claude-sonnet-5',
            inputTokens: 100,
            outputTokens: 20,
            cacheReadTokens: 5,
            cacheWrite5mTokens: 7,
            cacheWrite1hTokens: 9,
        );
        self::assertSame(
            ['MODEL', 'claude-sonnet-5', 'INPUT', 100, 'OUTPUT', 20, 'CACHEREAD', 5, 'CACHEWRITE5M', 7, 'CACHEWRITE1H', 9],
            $usage->toArgs(),
        );
    }

    public function testASingleNonZeroCountIsIncludedAlone(): void
    {
        $usage = new TokenUsage(model: 'm', cacheWrite1hTokens: 3);
        self::assertSame(['MODEL', 'm', 'CACHEWRITE1H', 3], $usage->toArgs());
    }

    public function testCountsDefaultToZero(): void
    {
        $usage = new TokenUsage(model: 'm');
        self::assertSame(0, $usage->inputTokens);
        self::assertSame(0, $usage->outputTokens);
        self::assertSame(0, $usage->cacheReadTokens);
        self::assertSame(0, $usage->cacheWrite5mTokens);
        self::assertSame(0, $usage->cacheWrite1hTokens);
    }
}
