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
 * The four identical keyed cache families: same engine, different
 * namespace prefix and license feature.
 *
 *   | Reach via              | Wire prefix   |
 *   |-------------------------|---------------|
 *   | `$client->toolCache()`  | `TOOL.CACHE`  |
 *   | `$client->ncache()`     | `NCACHE`      |
 *   | `$client->guard()`      | `GUARD`       |
 *   | `$client->rerank()`     | `RERANK`      |
 *
 * Each needs a server license that includes its own feature.
 */
final class KeyedCacheCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
        private readonly string $prefix,
    ) {
    }

    /** @return string "OK" */
    public function create(string $ns, ?int $maxEntries = null, ?int $ttl = null): mixed
    {
        $args = Args::new("{$this->prefix}.CREATE", $ns)->opt('MAX_ENTRIES', $maxEntries)->opt('TTL', $ttl);

        return $this->client->execute($args->toArray());
    }

    /**
     * $usage, if given, records the original (uncached) call's token
     * usage so a later hit attributes it (and its cost, once the model
     * is priced) to stats()'s usage_saved_* counters.
     *
     * @return string "OK"
     */
    public function set(string $ns, string $key, string $value, ?int $ttl = null, ?string $meta = null, ?TokenUsage $usage = null): mixed
    {
        $args = Args::new("{$this->prefix}.SET", $ns, $key, $value)->opt('TTL', $ttl)->opt('META', $meta);
        if ($usage !== null) {
            $args->pushAll($usage->toArgs());
        }

        return $this->client->execute($args->toArray());
    }

    /** @return string|null the value, or null on a miss */
    public function get(string $ns, string $key): mixed
    {
        return $this->client->execute(["{$this->prefix}.GET", $ns, $key]);
    }

    /** @return int 1/0 */
    public function del(string $ns, string $key): mixed
    {
        return $this->client->execute(["{$this->prefix}.DEL", $ns, $key]);
    }

    /**
     * @return array{entries: int, hits: int, misses: int, evictions: int,
     *     memory_bytes: int, usage_saved_requests: int, usage_saved_input_tokens: int,
     *     usage_saved_output_tokens: int, usage_saved_cache_read_tokens: int,
     *     usage_saved_cache_write_tokens: int, usage_saved_cost_usd: string}
     */
    public function stats(string $ns): mixed
    {
        return Reply::toMap($this->client->execute(["{$this->prefix}.STATS", $ns]));
    }

    /** @return int entries flushed */
    public function flush(string $ns): mixed
    {
        return $this->client->execute(["{$this->prefix}.FLUSH", $ns]);
    }

    /** @return int 1/0 */
    public function drop(string $ns): mixed
    {
        return $this->client->execute(["{$this->prefix}.DROP", $ns]);
    }

    /** @return list<string> namespace names */
    public function list(): mixed
    {
        return $this->client->execute(["{$this->prefix}.LIST"]);
    }
}
