<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\PubSub;

use HotKV\Connection;
use HotKV\Exception\ProtocolException;
use HotKV\Protocol\PushMessage;

/**
 * Runs one blocking pub/sub loop on a dedicated connection. Built by
 * Client::subscribe()/psubscribe()/ssubscribe(); not constructed
 * directly.
 *
 * The loop blocks reading the next message and can only be stopped from
 * inside the callback (there is no other thread to call stop() from in a
 * synchronous client): call $subscriber->stop() there once you are done.
 */
final class Subscriber
{
    private bool $stopped = false;

    /** @param list<string> $names */
    public function __construct(
        private readonly Connection $conn,
        private readonly string $subscribeCmd,
        private readonly string $unsubscribeCmd,
        private readonly array $names,
    ) {
    }

    /** Stops the loop after the callback currently running returns. */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @param callable(Message, self): void $onMessage */
    public function run(callable $onMessage): void
    {
        if ($this->names === []) {
            return;
        }

        $this->conn->queue(array_merge([$this->subscribeCmd], $this->names));
        $this->conn->flush();

        // One confirmation frame per channel/pattern subscribed to.
        for ($i = 0, $n = count($this->names); $i < $n; $i++) {
            $this->nextFrame();
        }

        while (!$this->stopped) {
            $frame = $this->nextFrame();
            $kind = strtolower((string) ($frame[0] ?? ''));

            match ($kind) {
                'message' => $onMessage(new Message('message', (string) $frame[1], null, (string) $frame[2]), $this),
                'smessage' => $onMessage(new Message('smessage', (string) $frame[1], null, (string) $frame[2]), $this),
                'pmessage' => $onMessage(new Message('pmessage', (string) $frame[2], (string) $frame[1], (string) $frame[3]), $this),
                // Subscribe/unsubscribe acknowledgements carry a running
                // subscription count, not a message; nothing to deliver.
                default => null,
            };
        }

        $this->unsubscribeAll();
    }

    /** @return list<mixed> */
    private function nextFrame(): array
    {
        $reply = $this->conn->readRaw();
        if ($reply instanceof PushMessage) {
            return $reply->elements;
        }
        if (is_array($reply)) {
            return $reply;
        }

        throw new ProtocolException('Expected a pub/sub push frame from the HotKV server.');
    }

    private function unsubscribeAll(): void
    {
        try {
            $this->conn->queue([$this->unsubscribeCmd]);
            $this->conn->flush();
        } catch (\Throwable) {
            // The connection is being torn down regardless; a failed
            // best effort unsubscribe must not mask why the loop stopped.
        }
    }
}
