<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Support;

/**
 * The one place every float argument the SDK sends is turned into text.
 *
 * Why this exists: PHP's own float to string conversions are not safe for
 * a wire protocol. `(string) 1.0E-5`, `var_export`, `json_encode` and
 * `print` all produce exponent form ("1.0E-5") for small and large
 * magnitudes, `serialize_precision` and `precision` ini settings change the
 * digits, and `sprintf('%f')` honours the process locale (a comma as the
 * decimal separator). The HotKV server reads several float arguments with
 * strict plain-decimal parsers (LLMBUDGET.SET and LLMSTATS.PRICE SET read a
 * fixed-point USD amount: digits, optional '.', at most 6 fractional digits,
 * no sign, no exponent) so exponent form must never reach the wire.
 *
 * Everything here is locale independent and independent of the ini settings.
 */
final class Numbers
{
    /** Largest whole-dollar amount LLMBUDGET.SET / LLMSTATS.PRICE accept (uint64 micro-dollars). */
    private const USD_MAX = 18446744073709.0;

    /**
     * Shortest plain decimal text that round trips to the same float, with
     * no exponent. A whole number keeps a trailing ".0" ("1.0", "-2.0") so
     * it is never mistaken for an integer token. +INF and -INF become
     * "inf" and "-inf", which standard numeric arguments (ZADD scores,
     * ZINCRBY, ZUNIONSTORE weights) accept. NaN is rejected: no HotKV or
     * numeric argument takes it.
     *
     * @throws \InvalidArgumentException for NaN
     */
    public static function format(float $n): string
    {
        if (is_nan($n)) {
            throw new \InvalidArgumentException('NaN is not a valid numeric argument.');
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'inf' : '-inf';
        }

        return self::plain($n);
    }

    /**
     * Like format(), but also rejects +INF and -INF. For arguments where
     * the server refuses an infinite value (GEO coordinates and radii,
     * INCRBYFLOAT, vector components, thresholds, costs, timeouts, rates).
     *
     * @throws \InvalidArgumentException for NaN, INF or -INF
     */
    public static function finite(float $n): string
    {
        if (is_infinite($n)) {
            throw new \InvalidArgumentException('An infinite value is not valid for this numeric argument.');
        }

        return self::format($n);
    }

    /**
     * A USD amount in the fixed-point form the server's money parser
     * requires (llm_pricing::parse_usd_to_micros): digits, an optional '.'
     * and 1 to 6 fractional digits, no sign, no exponent. A float is
     * rounded to the nearest micro-dollar and trailing zeros are trimmed
     * ("50", "0.000012"). A string is the caller's own decimal text and is
     * passed through unchanged for the server to validate.
     *
     * @throws \InvalidArgumentException for NaN, INF, a negative amount, an
     *     amount too large for the server, or a positive amount smaller
     *     than one micro-dollar (it would round to 0, which the server
     *     reads as "no limit", the opposite of what was asked)
     */
    public static function usd(float|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException('A USD amount must be a finite number.');
        }
        if ($value < 0.0) {
            throw new \InvalidArgumentException('A USD amount cannot be negative.');
        }
        if ($value > self::USD_MAX) {
            throw new \InvalidArgumentException('A USD amount this large does not fit the server\'s fixed-point money type.');
        }
        // 'F' (not 'f') is the locale independent conversion.
        $text = sprintf('%.6F', $value);
        $text = str_replace(',', '.', $text);
        if ($value > 0.0 && (float) $text === 0.0) {
            throw new \InvalidArgumentException('A USD amount below one micro-dollar (0.000001) cannot be represented.');
        }
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /** Plain decimal text for a finite float; see format(). */
    private static function plain(float $n): string
    {
        if ($n === 0.0) {
            return fdiv(1.0, $n) < 0.0 ? '-0.0' : '0.0';
        }
        $sign = $n < 0.0 ? '-' : '';
        $abs = abs($n);

        // Find the fewest significant digits that still parse back to the
        // same float. '%E' is used only to obtain correctly rounded digits
        // and a decimal exponent; the text is rebuilt by hand below.
        $sci = '';
        for ($precision = 0; $precision <= 16; $precision++) {
            $sci = sprintf('%.' . $precision . 'E', $abs);
            if ((float) str_replace(',', '.', $sci) === $abs) {
                break;
            }
        }
        if (preg_match('/^(\d)(?:[.,](\d+))?E([+-]\d+)$/', $sci, $m) !== 1) {
            throw new \LogicException('Unexpected float conversion: ' . $sci);
        }
        $digits = rtrim($m[1] . ($m[2] ?? ''), '0');
        if ($digits === '') {
            $digits = '0';
        }
        $exp = (int) $m[3];

        if ($exp >= 0) {
            if (strlen($digits) <= $exp + 1) {
                $int = str_pad($digits, $exp + 1, '0');
                $frac = '0';
            } else {
                $int = substr($digits, 0, $exp + 1);
                $frac = substr($digits, $exp + 1);
            }
        } else {
            $int = '0';
            $frac = str_repeat('0', -$exp - 1) . $digits;
        }

        return $sign . $int . '.' . $frac;
    }
}
