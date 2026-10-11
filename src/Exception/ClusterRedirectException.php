<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Base type for the two cluster redirect replies, MOVED and ASK. Both
 * carry the hash slot and the "host:port" of the node that owns it.
 */
abstract class ClusterRedirectException extends ServerException
{
    private int $slot;
    private string $host;
    private int $port;

    public function __construct(string $prefix, string $raw, int $slot, string $host, int $port)
    {
        parent::__construct($prefix, $raw);
        $this->slot = $slot;
        $this->host = $host;
        $this->port = $port;
    }

    protected static function parts(string $raw): array
    {
        // "MOVED <slot> <host>:<port>" / "ASK <slot> <host>:<port>"
        $tokens = preg_split('/\s+/', trim($raw));
        if (count($tokens) < 3) {
            throw new ProtocolException("Malformed cluster redirect reply: {$raw}");
        }
        $slot = (int) $tokens[1];
        $hostPort = $tokens[2];
        $colon = strrpos($hostPort, ':');
        if ($colon === false) {
            throw new ProtocolException("Malformed cluster redirect target: {$hostPort}");
        }

        return [$slot, substr($hostPort, 0, $colon), (int) substr($hostPort, $colon + 1)];
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }
}
