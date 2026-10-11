<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

/**
 * The outcome of a PromptCache or SemanticCache getOrCall(): the answer,
 * whether it came from cache, and (for PromptCache) the deterministic
 * key it was stored under.
 */
final class CacheResult
{
    public function __construct(
        public readonly string $response,
        public readonly bool $hit,
        public readonly ?string $key = null,
    ) {
    }
}
