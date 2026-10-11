<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

/**
 * Implemented by every class the command traits (HotKV\Commands\*) can be
 * mixed into: Client (executes immediately), Pipeline (queues and returns
 * itself) and Transaction (queues inside MULTI and returns itself).
 */
interface CommandDispatcher
{
    /**
     * @param list<string|int|float|bool> $args
     * @return mixed the command's result (Client), or the dispatcher
     *     itself for chaining (Pipeline, Transaction)
     */
    public function dispatch(array $args): mixed;
}
