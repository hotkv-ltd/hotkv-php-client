<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

use HotKV\TokenUsage;

/**
 * What the caller's function passed to SemanticCache::getOrCall() may
 * return instead of a plain string, to also report the original call's
 * token usage. $usage, if given, is stored with the SCACHE entry so a
 * later hit attributes its tokens, and once the model is priced its
 * cost, to SemanticCache::stats()'s usage_saved_* counters.
 */
final class SemanticCallResult
{
    public function __construct(
        public readonly string $response,
        public readonly ?TokenUsage $usage = null,
    ) {
    }
}
