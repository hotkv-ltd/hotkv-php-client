<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

/**
 * One model call's token usage: the token usage group shared by
 * PCACHE.SET, SCACHE.SET, every keyed cache SET (TOOL.CACHE/NCACHE/
 * GUARD/RERANK) and LLMBUDGET.CONSUME.
 *
 * Recording a TokenUsage with a cache entry lets a later cache hit
 * attribute its tokens, and once the model is priced its exact cost, to
 * the namespace's usage_saved_* stats fields.
 *
 * $model is required; every count defaults to 0 and is left off the wire
 * when zero (MODEL itself is always sent once a TokenUsage is given).
 */
final class TokenUsage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWrite5mTokens = 0,
        public readonly int $cacheWrite1hTokens = 0,
    ) {
    }

    /**
     * The `MODEL <model> [INPUT n] [OUTPUT n] [CACHEREAD n] [CACHEWRITE5M
     * n] [CACHEWRITE1H n]` KEY VALUE pairs this usage adds to a command's
     * trailing options.
     *
     * @return list<string|int>
     */
    public function toArgs(): array
    {
        $args = ['MODEL', $this->model];
        if ($this->inputTokens !== 0) {
            $args[] = 'INPUT';
            $args[] = $this->inputTokens;
        }
        if ($this->outputTokens !== 0) {
            $args[] = 'OUTPUT';
            $args[] = $this->outputTokens;
        }
        if ($this->cacheReadTokens !== 0) {
            $args[] = 'CACHEREAD';
            $args[] = $this->cacheReadTokens;
        }
        if ($this->cacheWrite5mTokens !== 0) {
            $args[] = 'CACHEWRITE5M';
            $args[] = $this->cacheWrite5mTokens;
        }
        if ($this->cacheWrite1hTokens !== 0) {
            $args[] = 'CACHEWRITE1H';
            $args[] = $this->cacheWrite1hTokens;
        }

        return $args;
    }
}
