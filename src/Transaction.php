<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Commands\BitmapCommands;
use HotKV\Commands\GeoCommands;
use HotKV\Commands\HashCommands;
use HotKV\Commands\HyperLogLogCommands;
use HotKV\Commands\KeyCommands;
use HotKV\Commands\ListCommands;
use HotKV\Commands\PubSubCommands;
use HotKV\Commands\ScriptingCommands;
use HotKV\Commands\ServerCommands;
use HotKV\Commands\SetCommands;
use HotKV\Commands\SortedSetCommands;
use HotKV\Commands\StreamCommands;
use HotKV\Commands\StringCommands;
use HotKV\Protocol\ReplyError;

/**
 * A MULTI/EXEC transaction on one connection. Built by
 * Client::transaction(): every typed command method issued on it inside
 * the callback is queued with MULTI (started the first time a command is
 * dispatched) and returns $this for chaining; exec() sends EXEC and
 * returns the results in order, or null if the transaction was aborted
 * because a key watched with Client::watch() changed first.
 */
final class Transaction implements CommandDispatcher
{
    use StringCommands;
    use KeyCommands;
    use ListCommands;
    use SetCommands;
    use HashCommands;
    use SortedSetCommands;
    use StreamCommands;
    use GeoCommands;
    use BitmapCommands;
    use HyperLogLogCommands;
    use ServerCommands;
    use ScriptingCommands;
    use PubSubCommands;

    private bool $inMulti = false;
    private bool $discarded = false;

    public function __construct(
        private readonly Connection $conn,
    ) {
    }

    /** Queues any command; the escape hatch for commands with no typed method. */
    public function command(string|int|float|bool ...$args): static
    {
        return $this->dispatch(array_values($args));
    }

    public function dispatch(array $args): static
    {
        $this->begin();
        // MULTI accepted the command with "+QUEUED"; an immediate error
        // here (bad arity, unknown command) throws right away instead of
        // silently corrupting the transaction.
        $this->conn->sendCommand($args);

        return $this;
    }

    private function begin(): void
    {
        if (!$this->inMulti) {
            $this->conn->sendCommand(['MULTI']);
            $this->inMulti = true;
        }
    }

    /**
     * Sends EXEC. Returns the queued commands' results in order (a
     * command the server rejected appears as a ServerException in its
     * slot rather than being thrown), or null if the transaction aborted.
     * Safe to call with nothing queued: returns an empty array.
     *
     * @return list<mixed>|null
     */
    public function exec(): ?array
    {
        if ($this->discarded) {
            return null;
        }
        if (!$this->inMulti) {
            return [];
        }
        $this->inMulti = false;
        $reply = $this->conn->sendCommand(['EXEC']);
        if ($reply === null) {
            return null;
        }

        return array_map(
            static fn (mixed $v) => $v instanceof ReplyError ? $v->toException() : $v,
            $reply,
        );
    }

    /** Discards a transaction in progress; safe to call even if MULTI was never started. */
    public function discard(): void
    {
        if ($this->inMulti && !$this->discarded) {
            $this->conn->sendCommand(['DISCARD']);
        }
        $this->inMulti = false;
        $this->discarded = true;
    }
}
