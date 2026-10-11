<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Protocol;

/**
 * A RESP3 out of band push reply (">..."), used for pub/sub message
 * delivery and client side invalidation. A regular command reply is never
 * a PushMessage; only the pub/sub subscriber loop reads them.
 *
 * @property-read list<mixed> $elements
 */
final class PushMessage
{
    /** @param list<mixed> $elements */
    public function __construct(
        public readonly array $elements,
    ) {
    }
}
