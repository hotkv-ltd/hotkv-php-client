<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

use HotKV\Client;
use HotKV\Exception\NotLicensedException;
use HotKV\Exception\ServerException;

/**
 * An idiomatic get-or-call cache in front of SCACHE.*: looks up the
 * nearest cached answer to a caller-supplied embedding vector above the
 * namespace's similarity threshold, calling the caller's function only
 * on a miss. Never computes embeddings itself; the SDK has no dependency
 * on any particular embedding provider. Needs a server license that
 * includes the `semantic-cache` feature.
 */
final class SemanticCache
{
    public function __construct(
        private readonly Client $client,
        private readonly string $namespace,
        private readonly ?int $ttlSeconds = null,
    ) {
    }

    /**
     * Ensures the SCACHE namespace backing this cache exists, with the
     * given embedding dimensionality, distance metric ("cosine", "l2" or
     * "ip") and similarity threshold (0.0 to 1.0). Safe to call more than
     * once.
     */
    public function createNamespace(int $dims, string $metric = 'cosine', float $threshold = 0.85): void
    {
        try {
            $this->client->scache()->create($this->namespace, $dims, $metric, $threshold, $this->ttlSeconds ?? 0);
        } catch (ServerException $e) {
            if ($e instanceof NotLicensedException || !str_contains($e->getRaw(), 'already exists')) {
                throw $e;
            }
        }
    }

    /**
     * Returns the cached answer for the nearest neighbor of $vector above
     * the namespace's similarity threshold, if any; otherwise calls $fn,
     * caches (query, response, vector) and returns the fresh answer.
     *
     * $fn may return either just the response, or a SemanticCallResult
     * (response plus a TokenUsage); when it does, that usage is stored
     * with the cache entry (see Enterprise\ScacheCommands::set()) so a
     * later hit attributes its savings through stats().
     *
     * @param list<float> $vector
     * @param callable(): (string|SemanticCallResult) $fn called only on a miss
     */
    public function getOrCall(string $query, array $vector, callable $fn): CacheResult
    {
        try {
            $hit = $this->client->scache()->get($this->namespace, $vector);
        } catch (NotLicensedException) {
            $hit = null;
        }

        if ($hit !== null) {
            return new CacheResult($hit['response'], true);
        }

        $result = $fn();
        $response = is_string($result) ? $result : $result->response;
        $usage = is_string($result) ? null : $result->usage;

        try {
            $this->client->scache()->set($this->namespace, $query, $response, $vector, $this->ttlSeconds, usage: $usage);
        } catch (NotLicensedException) {
            // The cache is unavailable, not the caller's answer source.
        }

        return new CacheResult($response, false);
    }

    /**
     * This namespace's SCACHE.STATS, including the usage_saved_* savings
     * counters attributed from any usage stored with an entry (see
     * SemanticCallResult).
     *
     * @return array{entries: int, hits: int, misses: int, hit_rate: string,
     *     p50_us: int, usage_saved_requests: int, usage_saved_input_tokens: int,
     *     usage_saved_output_tokens: int, usage_saved_cache_read_tokens: int,
     *     usage_saved_cache_write_tokens: int, usage_saved_cost_usd: string}
     */
    public function stats(): mixed
    {
        return $this->client->scache()->stats($this->namespace);
    }
}
