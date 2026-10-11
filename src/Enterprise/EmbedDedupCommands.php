<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Reply;

/**
 * EMBED.DEDUP.* : reference-counted embedding deduplication
 * (needs a server license that includes the `embed-dedup` feature).
 * Reach this via `$client->embedDedup()`.
 */
final class EmbedDedupCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return int 1 if newly stored, 0 if $hash already existed (its refcount was incremented instead) */
    public function put(string $ns, string $hash, string $model, string $embedding): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.PUT', $ns, $hash, $model, $embedding]);
    }

    /** @return string|null */
    public function get(string $ns, string $hash): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.GET', $ns, $hash]);
    }

    /** Decrements the refcount; the entry is freed at 0. @return int 1/0 */
    public function release(string $ns, string $hash): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.RELEASE', $ns, $hash]);
    }

    /** @return array{hits: int, misses: int, entries: int} */
    public function stats(string $ns): mixed
    {
        return Reply::toMap($this->client->execute(['EMBED.DEDUP.STATS', $ns]));
    }

    /** Drops one namespace. @return int 1/0 */
    public function drop(string $ns): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.DROP', $ns]);
    }

    /** Purges expired/unreferenced entries in one namespace. @return int 1/0 */
    public function purge(string $ns): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.PURGE', $ns]);
    }

    /** @return list<string> namespace names */
    public function list(): mixed
    {
        return $this->client->execute(['EMBED.DEDUP.LIST']);
    }
}
