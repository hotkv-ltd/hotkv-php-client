<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\CommandDispatcher;

/**
 * Lets a command trait shape a reply (e.g. flatten RESP2/RESP3 shape
 * differences into one PHP array) while staying shared between Client,
 * Pipeline and Transaction.
 *
 * On Client, dispatch() already returned the real, decoded reply, so it
 * is passed through $normalizer. On Pipeline/Transaction, dispatch()
 * returned the dispatcher itself for chaining, since the real reply is
 * not known until execute()/exec() runs later; that placeholder is
 * returned untouched so chaining keeps working.
 */
trait NormalizesReplies
{
    private function normalized(mixed $result, callable $normalizer): mixed
    {
        return $result instanceof CommandDispatcher ? $result : $normalizer($result);
    }
}
