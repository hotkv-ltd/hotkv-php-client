<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;

/**
 * HOTKEYS : most frequently accessed key tracking. Admin; works without
 * a license. Reach this via `$client->hotkeys()`.
 */
final class HotkeysCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return list<array{0: string, 1: int}> [key, count], most accessed first */
    public function top(int $count = 10): mixed
    {
        return $this->client->execute(['HOTKEYS', 'TOP', $count]);
    }

    /** @return string "OK" */
    public function reset(): mixed
    {
        return $this->client->execute(['HOTKEYS', 'RESET']);
    }
}
