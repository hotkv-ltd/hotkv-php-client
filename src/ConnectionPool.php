<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Exception\ConnectionException;

/**
 * A bounded pool of Connections to one HotKV server. Connections idle
 * past poolIdleTimeout are health checked with PING when borrowed again
 * and silently replaced if that fails.
 *
 * PHP normally executes one request at a time with no real threads, so
 * the common case (Client::persistent or a single Client used from one
 * script) never needs more than one connection; the pool exists for
 * Fiber based or embedded uses that genuinely borrow from more than one
 * place. It is not a blocking pool: once poolSize connections are active,
 * borrow() throws rather than waiting, since PHP has no portable way to
 * suspend and be woken by an unrelated Fiber or process.
 */
final class ConnectionPool
{
    /** @var list<Connection> */
    private array $idle = [];
    private int $active = 0;
    /** @var (callable(Protocol\PushMessage): void)|null */
    private $pushHandler = null;
    private readonly int $maxSize;
    private readonly float $idleTimeout;

    public function __construct(
        private readonly ClientOptions $options,
    ) {
        $this->maxSize = max(1, $options->poolSize);
        $this->idleTimeout = $options->poolIdleTimeout;
    }

    public function borrow(): Connection
    {
        while ($this->idle !== []) {
            $conn = array_pop($this->idle);
            if ($this->idleTimeout > 0
                && (microtime(true) - $conn->lastUsedAt()) > $this->idleTimeout
                && !$conn->ping()
            ) {
                // Stale and no longer alive: drop it and try the next
                // idle connection, or fall through to opening a new one.
                continue;
            }
            $this->active++;

            return $conn;
        }

        if ($this->active >= $this->maxSize) {
            throw new ConnectionException(
                "HotKV connection pool exhausted (max size {$this->maxSize}); release a connection before borrowing another.",
            );
        }

        $this->active++;
        $conn = new Connection($this->options);
        $conn->setPushHandler($this->pushHandler);

        return $conn;
    }

    /** @param (callable(Protocol\PushMessage): void)|null $handler */
    public function setPushHandler(?callable $handler): void
    {
        $this->pushHandler = $handler;
        foreach ($this->idle as $conn) {
            $conn->setPushHandler($handler);
        }
    }

    public function release(Connection $conn): void
    {
        $this->active = max(0, $this->active - 1);
        $this->idle[] = $conn;
    }

    /** Returns a connection that should not be reused (e.g. it errored) to the pool as broken. */
    public function discard(Connection $conn): void
    {
        $conn->close();
        $this->active = max(0, $this->active - 1);
    }

    public function close(): void
    {
        foreach ($this->idle as $conn) {
            $conn->close();
        }
        $this->idle = [];
        $this->active = 0;
    }

    public function size(): int
    {
        return $this->active + count($this->idle);
    }
}
