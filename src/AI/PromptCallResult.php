<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

use HotKV\TokenUsage;

/**
 * What the caller's function passed to PromptCache::getOrCall() returns
 * on a cache miss: the LLM's answer plus usage accounting, so PromptCache
 * can report it to LLMSTATS when a stats bucket is configured.
 *
 * $usage, if given, is stored with the PCACHE entry (see
 * Enterprise\PcacheCommands::set()) so a later hit attributes its tokens,
 * and once the model is priced its cost, to PromptCache::stats()'s
 * usage_saved_* counters; when its model/cache-write fields are set they
 * are also forwarded to the LLMSTATS.RECORD call a stats bucket makes.
 */
final class PromptCallResult
{
    public function __construct(
        public readonly string $response,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $costUsd = 0.0,
        public readonly int $latencyMs = 0,
        public readonly ?TokenUsage $usage = null,
    ) {
    }
}
