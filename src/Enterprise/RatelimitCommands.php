<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Numbers;
use HotKV\Support\Reply;

/**
 * RATELIMIT.* : named rate limiters (needs a server license that
 * includes the `rate-limiting` feature). Reach this via
 * `$client->ratelimit()`. See also `$client->gcra()` for the lower level
 * GCRA primitive this is distinct from.
 */
final class RatelimitCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function create(string $name, int $limit, int $windowMs, string $algo = 'sliding_window', ?float $refill = null, ?float $drain = null): mixed
    {
        $args = Args::new('RATELIMIT.CREATE', $name, $limit, $windowMs)->push('ALGO', $algo);
        if ($refill !== null) {
            $args->push('REFILL', Numbers::finite($refill));
        }
        if ($drain !== null) {
            $args->push('DRAIN', Numbers::finite($drain));
        }

        return $this->client->execute($args->toArray());
    }

    /** Mutating: advances limiter state. @return array{allowed: int, remaining: int, retry_after_ms: int, limit: int, current: int} */
    public function check(string $name, string $key, int $cost = 1): mixed
    {
        return self::mapResult($this->client->execute(['RATELIMIT.CHECK', $name, $key, 'COST', $cost]));
    }

    /** Non-mutating: does not consume. @return array{allowed: int, remaining: int, retry_after_ms: int, limit: int, current: int} */
    public function peek(string $name, string $key): mixed
    {
        return self::mapResult($this->client->execute(['RATELIMIT.PEEK', $name, $key]));
    }

    /** @return string "OK" */
    public function reset(string $name, string $key): mixed
    {
        return $this->client->execute(['RATELIMIT.RESET', $name, $key]);
    }

    /** @return array{name: string, algo: string, limit: int, window_ms: int, unique_keys: int, total_allowed: int, total_denied: int} */
    public function stats(string $name): mixed
    {
        return Reply::toMap($this->client->execute(['RATELIMIT.STATS', $name]));
    }

    /** @return string "OK" */
    public function drop(string $name): mixed
    {
        return $this->client->execute(['RATELIMIT.DROP', $name]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['RATELIMIT.LIST']);
    }

    /** @return array{allowed: int, remaining: int, retry_after_ms: int, limit: int, current: int} */
    private static function mapResult(array $r): array
    {
        return ['allowed' => $r[0], 'remaining' => $r[1], 'retry_after_ms' => $r[2], 'limit' => $r[3], 'current' => $r[4]];
    }
}
