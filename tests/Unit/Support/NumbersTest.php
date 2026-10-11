<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Support;

use HotKV\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumbersTest extends TestCase
{
    /** @return array<string, array{float, string}> */
    public static function plainCases(): array
    {
        return [
            'zero' => [0.0, '0.0'],
            'negative zero' => [-0.0, '-0.0'],
            'one' => [1.0, '1.0'],
            'negative whole' => [-2.0, '-2.0'],
            'fraction' => [0.7, '0.7'],
            'pi-ish' => [3.14159, '3.14159'],
            'tiny 1e-5 (PHP prints 1.0E-5)' => [1.0E-5, '0.00001'],
            'tiny 1e-7' => [1.0E-7, '0.0000001'],
            'tiny 1.5e-10' => [1.5E-10, '0.00000000015'],
            'tiny negative' => [-1.0E-9, '-0.000000001'],
            'huge 1e15' => [1.0E+15, '1000000000000000.0'],
            'huge 1e21 (PHP prints 1.0E+21)' => [1.0E+21, '1000000000000000000000.0'],
            'huge 1.5e25' => [1.5E+25, '15000000000000000000000000.0'],
            'huge negative' => [-1.25E+20, '-125000000000000000000.0'],
            'long mantissa' => [0.1 + 0.2, '0.30000000000000004'],
            'int-valued large' => [9007199254740993.0, '9007199254740992.0'],
            'small whole with fraction' => [123456.789, '123456.789'],
            'smallest subnormal' => [5.0E-324, '0.' . str_repeat('0', 323) . '5'],
        ];
    }

    #[DataProvider('plainCases')]
    public function testFormatIsPlainDecimalAndRoundTrips(float $value, string $expected): void
    {
        $text = Numbers::format($value);
        self::assertSame($expected, $text);
        self::assertDoesNotMatchRegularExpression('/[eE]/', $text);
        self::assertSame($value, (float) $text);
    }

    public function testFormatIgnoresPrecisionIniSettings(): void
    {
        $oldPrecision = ini_set('precision', '5');
        $oldSerialize = ini_set('serialize_precision', '17');
        try {
            self::assertSame('0.1', Numbers::format(0.1));
            self::assertSame('0.30000000000000004', Numbers::format(0.1 + 0.2));
            self::assertSame('0.00001', Numbers::format(1.0E-5));
        } finally {
            ini_set('precision', (string) $oldPrecision);
            ini_set('serialize_precision', (string) $oldSerialize);
        }
    }

    public function testFormatIgnoresTheProcessLocale(): void
    {
        $previous = setlocale(LC_ALL, '0');
        $set = setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR');
        try {
            if ($set === false) {
                self::markTestSkipped('no comma-decimal locale is installed on this machine');
            }
            self::assertSame('1.5', Numbers::format(1.5));
            self::assertSame('0.00001', Numbers::format(1.0E-5));
            self::assertSame('0.000012', Numbers::usd(0.000012));
            self::assertSame('2.5', Numbers::usd(2.5));
        } finally {
            setlocale(LC_ALL, $previous === false ? 'C' : $previous);
        }
    }

    public function testFormatAllowsInfinityButFiniteDoesNot(): void
    {
        self::assertSame('inf', Numbers::format(INF));
        self::assertSame('-inf', Numbers::format(-INF));
        $this->expectException(\InvalidArgumentException::class);
        Numbers::finite(INF);
    }

    public function testFiniteRejectsNegativeInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Numbers::finite(-INF);
    }

    public function testNanIsRejectedEverywhere(): void
    {
        foreach ([fn () => Numbers::format(NAN), fn () => Numbers::finite(NAN), fn () => Numbers::usd(NAN)] as $call) {
            try {
                $call();
                self::fail('NaN was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFiniteMatchesFormatForOrdinaryValues(): void
    {
        self::assertSame('0.00001', Numbers::finite(1.0E-5));
        self::assertSame('-3.5', Numbers::finite(-3.5));
    }

    /** @return array<string, array{float, string}> */
    public static function usdCases(): array
    {
        return [
            'whole' => [50.0, '50'],
            'zero' => [0.0, '0'],
            'cents' => [0.01, '0.01'],
            'tiny 1e-5 (PHP prints 1.0E-5)' => [1.0E-5, '0.00001'],
            'one micro' => [1.0E-6, '0.000001'],
            'rounds to micros' => [0.0000123456, '0.000012'],
            'large' => [1.0E+9, '1000000000'],
            'mixed' => [12.5, '12.5'],
        ];
    }

    #[DataProvider('usdCases')]
    public function testUsdIsFixedPointWithAtMostSixDecimals(float $value, string $expected): void
    {
        $text = Numbers::usd($value);
        self::assertSame($expected, $text);
        // The server money parser wants a leading digit, optionally '.' plus 1..6 digits, no sign or exponent.
        self::assertMatchesRegularExpression('/^\d+(\.\d{1,6})?$/', $text);
    }

    public function testUsdPassesStringsThroughUnchanged(): void
    {
        self::assertSame('15.000001', Numbers::usd('15.000001'));
    }

    /** @return array<string, array{float}> */
    public static function badUsd(): array
    {
        return [
            'negative' => [-0.5],
            'infinite' => [INF],
            'below one micro-dollar would round to the no-limit 0' => [1.0E-7],
            'too large for uint64 micro-dollars' => [1.0E+14],
        ];
    }

    #[DataProvider('badUsd')]
    public function testUsdRejectsAmountsTheServerCannotHold(float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Numbers::usd($value);
    }
}
