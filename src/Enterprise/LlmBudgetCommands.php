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
 * LLMBUDGET.* : token/cost budget enforcement (needs a server license
 * that includes the `llm-budget` feature). Reach this via
 * `$client->llmbudget()`.
 */
final class LlmBudgetCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function set(string $name, int $tokenLimit, float $costLimitUsd, int $windowSeconds): mixed
    {
        return $this->client->execute(['LLMBUDGET.SET', $name, $tokenLimit, Numbers::usd($costLimitUsd), $windowSeconds]);
    }

    /**
     * Tries to consume $tokens/$costUsd from budget $name. Pass $usage
     * instead to have the server compute both from its LLMSTATS.PRICE
     * table (this sends tokens/cost_usd as 0/0.0 regardless of what is
     * passed, since the server ignores them).
     *
     * @return int 1 if allowed, 0 if rejected (would exceed the token or cost cap)
     */
    public function consume(string $name, int $tokens = 0, float $costUsd = 0.0, ?TokenUsage $usage = null): mixed
    {
        $args = Args::new('LLMBUDGET.CONSUME', $name, $tokens, Numbers::finite($costUsd));
        if ($usage !== null) {
            $args->pushAll($usage->toArgs());
        }

        return $this->client->execute($args->toArray());
    }

    /**
     * @return array{tokens_used: int, token_limit: int, cost_used_usd: string,
     *     cost_limit_usd: string, window_seconds: int, window_remaining: int,
     *     requests_allowed: int, requests_rejected: int, unpriced_requests: int}
     */
    public function status(string $name): mixed
    {
        return Reply::toMap($this->client->execute(['LLMBUDGET.STATUS', $name]));
    }

    /** @return int 1/0 */
    public function reset(string $name): mixed
    {
        return $this->client->execute(['LLMBUDGET.RESET', $name]);
    }

    /** @return int 1/0 */
    public function drop(string $name): mixed
    {
        return $this->client->execute(['LLMBUDGET.DROP', $name]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['LLMBUDGET.LIST']);
    }
}
