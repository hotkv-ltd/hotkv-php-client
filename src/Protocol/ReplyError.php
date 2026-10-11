<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Protocol;

use HotKV\Exception\ServerException;

/**
 * A decoded RESP error reply ("-ERR ..." or the RESP3 blob error "!...")
 * that has not been thrown yet.
 *
 * The reader never throws on an error reply by itself, because a Pipeline
 * must surface a per-command error without failing the whole batch. Code
 * that executes a single command outside a pipeline converts this to a
 * ServerException with toException() and throws it.
 */
final class ReplyError
{
    public function __construct(
        public readonly string $message,
        public readonly bool $isBlob = false,
    ) {
    }

    public function toException(): ServerException
    {
        return ServerException::fromRaw($this->message);
    }
}
