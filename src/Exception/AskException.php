<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when the server replies "ASK <slot> <host>:<port>": the slot is
 * mid-migration and this one key now lives on another node. The cluster
 * client sends ASKING then retries the command there, without updating
 * its slot map.
 */
class AskException extends ClusterRedirectException
{
    public static function parse(string $raw): self
    {
        [$slot, $host, $port] = self::parts($raw);

        return new self('ASK', $raw, $slot, $host, $port);
    }
}
