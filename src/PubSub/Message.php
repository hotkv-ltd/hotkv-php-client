<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\PubSub;

/**
 * One pub/sub message delivered to a subscribe()/psubscribe()/ssubscribe()
 * callback.
 */
final class Message
{
    /**
     * @param string $kind "message" (SUBSCRIBE), "pmessage" (PSUBSCRIBE)
     *     or "smessage" (SSUBSCRIBE)
     * @param string $channel the channel the message was published to
     * @param string|null $pattern the matched pattern, only set for "pmessage"
     * @param string $payload the message body
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $channel,
        public readonly ?string $pattern,
        public readonly string $payload,
    ) {
    }
}
