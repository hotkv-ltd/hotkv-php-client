<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Support;

use HotKV\CommandExecutor;

/**
 * A minimal stand-in for HotKV\Client used by command unit tests: every
 * Enterprise\* class only ever calls execute(array $args) on the
 * CommandExecutor it is given, so this records every call's exact
 * argument list (to assert new options build the right wire arguments)
 * and returns a canned reply (to assert parsing of canned RESP2/RESP3
 * replies), all without a live server.
 */
final class RecordingExecutor implements CommandExecutor
{
    /** @var list<list<string|int|float|bool>> */
    public array $calls = [];

    public function __construct(
        private readonly mixed $reply = null,
    ) {
    }

    public function execute(array $args): mixed
    {
        $this->calls[] = $args;

        return $this->reply;
    }

    /** @return list<string|int|float|bool> */
    public function lastCall(): array
    {
        return $this->calls[count($this->calls) - 1];
    }
}
