<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Reply;
use HotKV\TokenUsage;

/**
 * PCACHE.* : prompt/LLM response cache (needs a server license that
 * includes the `prompt-cache` feature). Reach this via `$client->pcache()`. See
 * also HotKV\AI\PromptCache for an idiomatic get-or-call wrapper with a
 * canonical, cross-SDK cache key builder.
 */
final class PcacheCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function create(string $ns, ?int $maxEntries = null, ?int $ttl = null, ?float $costPerHit = null): mixed
    {
        $args = Args::new('PCACHE.CREATE', $ns)->opt('MAX_ENTRIES', $maxEntries)->opt('TTL', $ttl);
        if ($costPerHit !== null) {
            $args->push('COST_PER_HIT', \HotKV\Support\Numbers::finite($costPerHit));
        }

        return $this->client->execute($args->toArray());
    }

    /**
     * $usage, if given, records the original (uncached) call's token
     * usage so a later hit attributes it (and its cost, once the model
     * is priced) to stats()'s usage_saved_* counters.
     *
     * @return string "OK"
     */
    public function set(string $ns, string $prompt, string $response, ?int $ttl = null, ?string $meta = null, ?TokenUsage $usage = null): mixed
    {
        $args = Args::new('PCACHE.SET', $ns, $prompt, $response)->opt('TTL', $ttl)->opt('META', $meta);
        if ($usage !== null) {
            $args->pushAll($usage->toArgs());
        }

        return $this->client->execute($args->toArray());
    }

    /** @return string|null the cached response, or null on a miss */
    public function get(string $ns, string $prompt): mixed
    {
        return $this->client->execute(['PCACHE.GET', $ns, $prompt]);
    }

    /** @return array{response: string, metadata: string, hit_count: int}|null */
    public function getex(string $ns, string $prompt): mixed
    {
        $reply = $this->client->execute(['PCACHE.GETEX', $ns, $prompt]);

        return $reply === null ? null : ['response' => $reply[0], 'metadata' => $reply[1], 'hit_count' => $reply[2]];
    }

    /** @return int 1/0 */
    public function del(string $ns, string $prompt): mixed
    {
        return $this->client->execute(['PCACHE.DEL', $ns, $prompt]);
    }

    /**
     * @return array{entries: int, hits: int, misses: int, evictions: int,
     *     memory_bytes: int, hit_rate: string, p50_ns: int, p95_ns: int,
     *     p99_ns: int, cost_saved_usd: string, usage_saved_requests: int,
     *     usage_saved_input_tokens: int, usage_saved_output_tokens: int,
     *     usage_saved_cache_read_tokens: int, usage_saved_cache_write_tokens: int,
     *     usage_saved_cost_usd: string}
     */
    public function stats(string $ns): mixed
    {
        return Reply::toMap($this->client->execute(['PCACHE.STATS', $ns]));
    }

    /** @return int entries flushed */
    public function flush(string $ns): mixed
    {
        return $this->client->execute(['PCACHE.FLUSH', $ns]);
    }

    /** @return int 1/0 */
    public function drop(string $ns): mixed
    {
        return $this->client->execute(['PCACHE.DROP', $ns]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['PCACHE.LIST']);
    }

    /** @return string "OK" */
    public function config(string $ns, string $parameter, string|int|float $value): mixed
    {
        return $this->client->execute(['PCACHE.CONFIG', $ns, 'SET', strtoupper($parameter), $value]);
    }
}
