<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when the server replies "MOVED <slot> <host>:<port>": the key's
 * slot is now permanently owned by another node. The cluster client
 * refreshes its slot map and retries there.
 */
class MovedException extends ClusterRedirectException
{
    public static function parse(string $raw): self
    {
        [$slot, $host, $port] = self::parts($raw);

        return new self('MOVED', $raw, $slot, $host, $port);
    }
}
