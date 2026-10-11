<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Protocol;

/**
 * A RESP3 big number reply ("(..."), kept as its exact decimal digit
 * string since it may not fit in a PHP int. Cast to string to get the
 * digits, or call toInt() when the value is known to fit a native int.
 */
final class BigNumber implements \Stringable
{
    public function __construct(
        public readonly string $digits,
    ) {
    }

    public function toInt(): int
    {
        return (int) $this->digits;
    }

    public function __toString(): string
    {
        return $this->digits;
    }
}
