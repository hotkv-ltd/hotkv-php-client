<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

/**
 * Stateless pub/sub commands (publishing and introspection). The blocking
 * subscribe loop itself is Client::subscribe()/psubscribe()/ssubscribe(),
 * backed by HotKV\PubSub\Subscriber, since it takes over a connection for
 * as long as it runs. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait PubSubCommands
{
    /** @return int number of subscribers that received the message */
    public function publish(string $channel, string $message): mixed
    {
        return $this->dispatch(['PUBLISH', $channel, $message]);
    }

    /** @return int number of shard subscribers that received the message */
    public function spublish(string $channel, string $message): mixed
    {
        return $this->dispatch(['SPUBLISH', $channel, $message]);
    }

    /** @return list<string> active channel names, optionally filtered by glob $pattern */
    public function pubsubChannels(?string $pattern = null): mixed
    {
        return $this->dispatch($pattern === null ? ['PUBSUB', 'CHANNELS'] : ['PUBSUB', 'CHANNELS', $pattern]);
    }

    /** @param list<string> $channels @return list<mixed> flat [channel, count, ...] */
    public function pubsubNumsub(array $channels): mixed
    {
        return $this->dispatch(array_merge(['PUBSUB', 'NUMSUB'], $channels));
    }

    public function pubsubNumpat(): mixed
    {
        return $this->dispatch(['PUBSUB', 'NUMPAT']);
    }

    /** @return list<string> */
    public function pubsubShardChannels(?string $pattern = null): mixed
    {
        return $this->dispatch($pattern === null ? ['PUBSUB', 'SHARDCHANNELS'] : ['PUBSUB', 'SHARDCHANNELS', $pattern]);
    }

    /** @param list<string> $channels @return list<mixed> flat [channel, count, ...] */
    public function pubsubShardNumsub(array $channels): mixed
    {
        return $this->dispatch(array_merge(['PUBSUB', 'SHARDNUMSUB'], $channels));
    }
}
