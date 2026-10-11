<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\LlmPrice;
use HotKV\Support\Args;
use HotKV\Support\Numbers;
use HotKV\Support\Reply;

/**
 * LLMSTATS.* : LLM usage accounting (needs a server license that
 * includes the `llm-stats` feature). Reach this via `$client->llmstats()`.
 */
final class LlmStatsCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** Idempotent create. @return string "OK" */
    public function bucket(string $name): mixed
    {
        return $this->client->execute(['LLMSTATS.BUCKET', $name]);
    }

    /**
     * $cachedTokens is cache-READ tokens. With $model, the server prices
     * the call itself from its LLMSTATS.PRICE table and ignores $costUsd
     * (pass 0.0); an unpriced model still records tokens, costs 0 and
     * counts toward unpriced_requests. For OpenAI, pass
     * `prompt_tokens - cached_tokens` as $inputTokens (OpenAI reports
     * cache hits as part of prompt_tokens, unlike Anthropic).
     *
     * @return string "OK"
     */
    public function record(
        string $bucket,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens,
        float $costUsd,
        int $latencyMs,
        ?bool $error = null,
        ?string $model = null,
        ?int $cacheWrite5mTokens = null,
        ?int $cacheWrite1hTokens = null,
    ): mixed {
        $args = Args::new('LLMSTATS.RECORD', $bucket, $inputTokens, $outputTokens, $cachedTokens, Numbers::finite($costUsd), $latencyMs);
        if ($error !== null) {
            $args->push('ERROR', $error ? 1 : 0);
        }
        $args->opt('MODEL', $model)->opt('CACHEWRITE5M', $cacheWrite5mTokens)->opt('CACHEWRITE1H', $cacheWrite1hTokens);

        return $this->client->execute($args->toArray());
    }

    /**
     * @return array{requests: int, errors: int, input_tokens: int,
     *     output_tokens: int, cached_tokens: int, cost_usd: string,
     *     avg_latency_ms: string, requests_1m: int, tokens_1m: int, cost_usd_1m: string,
     *     cache_write_5m_tokens: int, cache_write_1h_tokens: int, unpriced_requests: int}
     */
    public function get(string $bucket): mixed
    {
        return Reply::toMap($this->client->execute(['LLMSTATS.GET', $bucket]));
    }

    /** @return int 1/0 */
    public function reset(string $bucket): mixed
    {
        return $this->client->execute(['LLMSTATS.RESET', $bucket]);
    }

    /** @return int 1/0 */
    public function drop(string $bucket): mixed
    {
        return $this->client->execute(['LLMSTATS.DROP', $bucket]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['LLMSTATS.LIST']);
    }

    // -- LLMSTATS.PRICE (per model price table, used by record() and Enterprise\LlmBudgetCommands::consume() when given a model/usage) --

    /**
     * Sets a custom USD-per-1,000,000-token price for $model. Prices may
     * be a float or a decimal string; an omitted cache price defaults to
     * 0.1x/1.25x/2x $inputUsdPerMtok.
     *
     * @return string "OK"
     */
    public function priceSet(
        string $model,
        float|string $inputUsdPerMtok,
        float|string $outputUsdPerMtok,
        float|string|null $cacheReadUsdPerMtok = null,
        float|string|null $cacheWrite5mUsdPerMtok = null,
        float|string|null $cacheWrite1hUsdPerMtok = null,
    ): mixed {
        $args = Args::new('LLMSTATS.PRICE', 'SET', $model)
            ->push('INPUT', LlmPrice::formatPrice($inputUsdPerMtok))
            ->push('OUTPUT', LlmPrice::formatPrice($outputUsdPerMtok));
        if ($cacheReadUsdPerMtok !== null) {
            $args->push('CACHEREAD', LlmPrice::formatPrice($cacheReadUsdPerMtok));
        }
        if ($cacheWrite5mUsdPerMtok !== null) {
            $args->push('CACHEWRITE5M', LlmPrice::formatPrice($cacheWrite5mUsdPerMtok));
        }
        if ($cacheWrite1hUsdPerMtok !== null) {
            $args->push('CACHEWRITE1H', LlmPrice::formatPrice($cacheWrite1hUsdPerMtok));
        }

        return $this->client->execute($args->toArray());
    }

    /** $model's price table entry. An unknown model comes back with source "unpriced" and every price 0.0, not an error. */
    public function priceGet(string $model): LlmPrice
    {
        return LlmPrice::fromMap(Reply::toMap($this->client->execute(['LLMSTATS.PRICE', 'GET', $model])));
    }

    /** Every known model's price table entry (built in and custom). @return list<LlmPrice> */
    public function priceList(): array
    {
        $entries = $this->client->execute(['LLMSTATS.PRICE', 'LIST']);

        return array_map(static fn (array $entry): LlmPrice => LlmPrice::fromMap(Reply::toMap($entry)), $entries);
    }

    /** Removes $model's custom price override, if any (0 if there was none; the model then falls back to its built-in price, if any). @return int 1/0 */
    public function priceDel(string $model): mixed
    {
        return $this->client->execute(['LLMSTATS.PRICE', 'DEL', $model]);
    }
}
