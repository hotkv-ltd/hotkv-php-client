<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

/**
 * The single method every Enterprise\* command wrapper class actually
 * calls. Client implements this (alongside CommandDispatcher, used by
 * the core command traits) so those wrapper classes depend on this
 * narrow seam instead of the concrete, final Client, which lets a unit
 * test substitute a lightweight test double for a live connection.
 */
interface CommandExecutor
{
    /** @param list<string|int|float|bool> $args */
    public function execute(array $args): mixed;
}
