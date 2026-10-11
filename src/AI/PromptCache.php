<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

use HotKV\Client;
use HotKV\Exception\NotLicensedException;
use HotKV\Exception\ServerException;

/**
 * An idiomatic get-or-call cache in front of PCACHE.*: on a cache hit it
 * returns the stored response without invoking the LLM; on a miss it
 * calls the caller's function, stores the result, and returns it. Needs
 * `LicenseFeature::PromptCache` on the server; a NotLicensedException
 * from the cache itself is treated as "cache unavailable" and getOrCall()
 * falls through to calling the LLM rather than failing the request.
 *
 * PCACHE.STATS already tracks hits/misses for this namespace on the
 * server; when withStatsBucket() is set, a miss additionally reports its
 * real token/cost/latency usage to LLMSTATS.RECORD (a hit has no LLM
 * usage to report, since the LLM was not called).
 */
final class PromptCache
{
    private ?string $statsBucket = null;

    public function __construct(
        private readonly Client $client,
        private readonly string $namespace,
        private readonly ?int $ttlSeconds = null,
    ) {
    }

    /** Enables LLMSTATS.RECORD accounting on every miss, against $bucket (created if needed). Returns $this for chaining. */
    public function withStatsBucket(string $bucket): static
    {
        $this->statsBucket = $bucket;
        $this->client->llmstats()->bucket($bucket);

        return $this;
    }

    /** Ensures the PCACHE namespace backing this cache exists; safe to call more than once. */
    public function createNamespace(?int $maxEntries = null, ?float $costPerHit = null): void
    {
        try {
            $this->client->pcache()->create($this->namespace, $maxEntries, $this->ttlSeconds, $costPerHit);
        } catch (ServerException $e) {
            if ($e instanceof NotLicensedException || !str_contains($e->getRaw(), 'already exists')) {
                throw $e;
            }
        }
    }

    /**
     * Builds the deterministic cache key for (provider, model, system,
     * messages, params), returns the cached response if present,
     * otherwise calls $fn, caches its response and returns it.
     *
     * @param list<array{role: string, content: string}> $messages in the caller's given order
     * @param array<string, string> $params generation parameters, pre-formatted to strings by the caller
     * @param callable(): PromptCallResult $fn called only on a miss
     */
    public function getOrCall(
        string $provider,
        string $model,
        string $system,
        array $messages,
        array $params,
        callable $fn,
    ): CacheResult {
        $key = PromptCacheKey::build($provider, $model, $system, $messages, $params);

        try {
            $cached = $this->client->pcache()->get($this->namespace, $key);
        } catch (NotLicensedException) {
            $cached = null;
        }

        if ($cached !== null) {
            return new CacheResult($cached, true, $key);
        }

        $result = $fn();

        try {
            $this->client->pcache()->set($this->namespace, $key, $result->response, $this->ttlSeconds, usage: $result->usage);
        } catch (NotLicensedException) {
            // The cache is unavailable, not the LLM: still return the answer.
        }

        if ($this->statsBucket !== null) {
            $usage = $result->usage;
            // A result that only carries a TokenUsage (the README pattern)
            // has no separate inputTokens/outputTokens: take them from the
            // usage, or the server would price a model call of zero tokens.
            $this->client->llmstats()->record(
                $this->statsBucket,
                $result->inputTokens !== 0 ? $result->inputTokens : ($usage->inputTokens ?? 0),
                $result->outputTokens !== 0 ? $result->outputTokens : ($usage->outputTokens ?? 0),
                $usage?->cacheReadTokens ?? 0,
                $result->costUsd,
                $result->latencyMs,
                model: $usage?->model,
                cacheWrite5mTokens: $usage?->cacheWrite5mTokens,
                cacheWrite1hTokens: $usage?->cacheWrite1hTokens,
            );
        }

        return new CacheResult($result->response, false, $key);
    }

    /**
     * This namespace's PCACHE.STATS, including the usage_saved_* savings
     * counters attributed from any $usage stored with an entry (see
     * PromptCallResult).
     *
     * @return array{entries: int, hits: int, misses: int, evictions: int,
     *     memory_bytes: int, hit_rate: string, p50_ns: int, p95_ns: int,
     *     p99_ns: int, cost_saved_usd: string, usage_saved_requests: int,
     *     usage_saved_input_tokens: int, usage_saved_output_tokens: int,
     *     usage_saved_cache_read_tokens: int, usage_saved_cache_write_tokens: int,
     *     usage_saved_cost_usd: string}
     */
    public function stats(): mixed
    {
        return $this->client->pcache()->stats($this->namespace);
    }
}
