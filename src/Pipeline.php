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
 * Queues commands on one connection and sends them all in a single write,
 * reading back the replies in order. Built by Client::pipeline(); every
 * typed command method (get(), set(), zadd(), ...) is available and
 * returns $this so calls can be chained, since the real result is only
 * known once execute() reads the replies.
 *
 * A command that the server rejected is not thrown from execute(): its
 * slot in the returned array holds the ServerException instead, so one
 * bad command never discards the rest of the batch.
 */
final class Pipeline implements CommandDispatcher
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

    private int $queued = 0;

    public function __construct(
        private readonly Connection $conn,
    ) {
    }

    /** Sends any command; the escape hatch for commands with no typed method. */
    public function command(string|int|float|bool ...$args): static
    {
        return $this->dispatch(array_values($args));
    }

    public function dispatch(array $args): static
    {
        $this->conn->queue($args);
        $this->queued++;

        return $this;
    }

    /**
     * Flushes the queued commands and reads back their replies in order.
     *
     * @return list<mixed>
     */
    public function execute(): array
    {
        if ($this->queued === 0) {
            return [];
        }
        $this->conn->flush();
        $results = [];
        for ($i = 0; $i < $this->queued; $i++) {
            $reply = $this->conn->readReply();
            $results[] = $reply instanceof ReplyError ? $reply->toException() : $reply;
        }
        $this->queued = 0;

        return $results;
    }
}
