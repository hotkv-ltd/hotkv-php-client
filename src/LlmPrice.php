<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Support\Numbers;

/**
 * One LLMSTATS.PRICE table entry (the reply of
 * Enterprise\LlmStatsCommands::priceGet(), and each element of
 * priceList()). Prices are USD per 1,000,000 tokens. $source is
 * "default" (built in), "custom" (set with priceSet()) or "unpriced" (an
 * unknown model: every price 0.0, not an error).
 */
final class LlmPrice
{
    public function __construct(
        public readonly string $model = '',
        public readonly string $source = '',
        public readonly bool $priced = false,
        public readonly bool $retired = false,
        public readonly float $inputUsdPerMtok = 0.0,
        public readonly float $outputUsdPerMtok = 0.0,
        public readonly float $cacheReadUsdPerMtok = 0.0,
        public readonly float $cacheWrite5mUsdPerMtok = 0.0,
        public readonly float $cacheWrite1hUsdPerMtok = 0.0,
    ) {
    }

    /**
     * Builds an LlmPrice from a field-name keyed map (see
     * Support\Reply::toMap()); an unknown key is ignored, a missing one
     * falls back to this class's own default above. $priced/$retired
     * arrive as the server's integer 0/1.
     *
     * @param array<string, mixed> $fields
     */
    public static function fromMap(array $fields): self
    {
        return new self(
            model: (string) ($fields['model'] ?? ''),
            source: (string) ($fields['source'] ?? ''),
            priced: self::truthy($fields['priced'] ?? 0),
            retired: self::truthy($fields['retired'] ?? 0),
            inputUsdPerMtok: (float) ($fields['input_usd_per_mtok'] ?? 0.0),
            outputUsdPerMtok: (float) ($fields['output_usd_per_mtok'] ?? 0.0),
            cacheReadUsdPerMtok: (float) ($fields['cache_read_usd_per_mtok'] ?? 0.0),
            cacheWrite5mUsdPerMtok: (float) ($fields['cache_write_5m_usd_per_mtok'] ?? 0.0),
            cacheWrite1hUsdPerMtok: (float) ($fields['cache_write_1h_usd_per_mtok'] ?? 0.0),
        );
    }

    private static function truthy(mixed $value): bool
    {
        return $value === 1 || $value === true || $value === '1';
    }

    /**
     * Formats $value as the plain decimal string LLMSTATS.PRICE SET
     * requires: digits, optional ".", 1-6 fractional digits, no sign, no
     * exponent (see Support\Numbers::usd(): rounds to a micro-dollar, rejects a
     * negative, non-finite or sub-micro-dollar float). A string is passed
     * through unchanged (the caller's own decimal string).
     */
    public static function formatPrice(float|string $value): string
    {
        return Numbers::usd($value);
    }
}
