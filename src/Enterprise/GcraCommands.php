<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;

/**
 * GCRA.* : generic cell rate algorithm rate limiter primitive
 * distinct from RATELIMIT.*. Works without a license. Reach this via
 * `$client->gcra()`.
 */
final class GcraCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /**
     * @return array{denied: int, limit: int, remaining: int, retry_after_ms: int, reset_after_ms: int}
     */
    public function limit(string $key, int $periodMs, int $burst, int $count = 1): mixed
    {
        $reply = $this->client->execute(['GCRA.LIMIT', $key, $periodMs, $burst, $count]);

        return [
            'denied' => $reply[0],
            'limit' => $reply[1],
            'remaining' => $reply[2],
            'retry_after_ms' => $reply[3],
            'reset_after_ms' => $reply[4],
        ];
    }

    /** @param list<string> $keys @return int keys that had state and were reset */
    public function reset(array $keys): mixed
    {
        return $this->client->execute(array_merge(['GCRA.RESET'], $keys));
    }
}
