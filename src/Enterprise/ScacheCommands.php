<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Numbers;
use HotKV\Support\Reply;
use HotKV\TokenUsage;

/**
 * SCACHE.* : semantic cache (needs a server license that includes the
 * `semantic-cache` feature). Reach this via `$client->scache()`. See also HotKV\AI\SemanticCache for an idiomatic
 * get-or-call wrapper built on top of these.
 */
final class ScacheCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function create(string $ns, int $dims, string $metric = 'cosine', float $threshold = 0.85, int $ttl = 0): mixed
    {
        $args = Args::new('SCACHE.CREATE', $ns, $dims)
            ->push('METRIC', $metric)->push('THRESHOLD', Numbers::finite($threshold))->push('TTL', $ttl);

        return $this->client->execute($args->toArray());
    }

    /**
     * $usage, if given, records the original (uncached) call's token
     * usage so a later hit attributes it (and its cost, once the model
     * is priced) to stats()'s usage_saved_* counters.
     *
     * @param list<float> $vector
     * @return string "OK"
     */
    public function set(string $ns, string $queryText, string $response, array $vector, ?int $ttl = null, ?TokenUsage $usage = null): mixed
    {
        $args = Args::new('SCACHE.SET', $ns, $queryText, $response)->pushAll(array_map(Numbers::finite(...), $vector));
        $args->opt('TTL', $ttl);
        if ($usage !== null) {
            $args->pushAll($usage->toArgs());
        }

        return $this->client->execute($args->toArray());
    }

    /**
     * @param list<float> $vector
     * @return array{response: string, similarity: string}|null a hit above threshold, else null
     */
    public function get(string $ns, array $vector, ?float $thresholdOverride = null): mixed
    {
        $args = Args::new('SCACHE.GET', $ns)->pushAll(array_map(Numbers::finite(...), $vector));
        if ($thresholdOverride !== null) {
            $args->push('THRESHOLD', Numbers::finite($thresholdOverride));
        }
        $reply = $this->client->execute($args->toArray());

        return $reply === null ? null : ['response' => $reply[0], 'similarity' => $reply[1]];
    }

    /**
     * @return array{entries: int, hits: int, misses: int, hit_rate: string,
     *     p50_us: int, usage_saved_requests: int, usage_saved_input_tokens: int,
     *     usage_saved_output_tokens: int, usage_saved_cache_read_tokens: int,
     *     usage_saved_cache_write_tokens: int, usage_saved_cost_usd: string}
     */
    public function stats(string $ns): mixed
    {
        return Reply::toMap($this->client->execute(['SCACHE.STATS', $ns]));
    }

    /** @return int 1/0 */
    public function flush(string $ns): mixed
    {
        return $this->client->execute(['SCACHE.FLUSH', $ns]);
    }

    /** @return int 1/0 */
    public function drop(string $ns): mixed
    {
        return $this->client->execute(['SCACHE.DROP', $ns]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['SCACHE.LIST']);
    }

    /** @return string "OK" */
    public function threshold(string $ns, float $value): mixed
    {
        return $this->client->execute(['SCACHE.THRESHOLD', $ns, Numbers::finite($value)]);
    }
}
