<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when the server replies with a RESP error (simple "-ERR ..." or
 * RESP3 blob error "!..."). The error prefix (the first whitespace
 * separated token, e.g. "ERR", "WRONGTYPE", "NOLICENSE") is parsed out so
 * callers can branch on it without string matching the message text.
 *
 * fromRaw() returns the most specific subtype it recognizes (for example
 * NotLicensedException for a "NOLICENSE" prefix), so a plain
 * `catch (ServerException $e)` still works for callers who do not need the
 * distinction.
 */
class ServerException extends HotKVException
{
    private string $prefix;
    private string $raw;

    public function __construct(string $prefix, string $raw)
    {
        parent::__construct($raw);
        $this->prefix = $prefix;
        $this->raw = $raw;
    }

    /**
     * Builds the most specific ServerException subtype for a raw RESP
     * error string (without the leading '-' or '!' type byte).
     */
    public static function fromRaw(string $raw): self
    {
        $prefix = strtok($raw, " ") ?: $raw;

        return match ($prefix) {
            'NOLICENSE' => new NotLicensedException($prefix, $raw),
            'MOVED' => MovedException::parse($raw),
            'ASK' => AskException::parse($raw),
            'WRONGTYPE' => new WrongTypeException($prefix, $raw),
            'NOAUTH' => new NoAuthException($prefix, $raw),
            'READONLY' => new ReadOnlyException($prefix, $raw),
            default => new self($prefix, $raw),
        };
    }

    /** The error prefix, e.g. "ERR", "WRONGTYPE", "NOLICENSE", "MOVED". */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** The complete, unparsed error string returned by the server. */
    public function getRaw(): string
    {
        return $this->raw;
    }
}
