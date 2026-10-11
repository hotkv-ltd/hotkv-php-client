<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Protocol;

use HotKV\Exception\ConnectionException;
use HotKV\Exception\TimeoutException;
use HotKV\Support\Numbers;

/**
 * Encodes commands as RESP arrays of bulk strings and writes them to a
 * stream resource. Writes are buffered in memory until flush() is called,
 * so a Pipeline can queue many commands and send them in a single write
 * syscall.
 */
final class Writer
{
    /** @var resource */
    private $stream;
    private string $buffer = '';

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Appends one command (a flat list of arguments) to the write buffer
     * as a RESP array of bulk strings. Call flush() to actually send it.
     *
     * @param list<string|int|float|bool|\Stringable> $args
     */
    public function writeCommand(array $args): void
    {
        $this->buffer .= '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $this->buffer .= $this->encodeBulk($this->argToString($arg));
        }
    }

    public function argToString(string|int|float|bool|\Stringable $arg): string
    {
        return match (true) {
            is_string($arg) => $arg,
            is_int($arg) => (string) $arg,
            is_float($arg) => self::formatFloat($arg),
            is_bool($arg) => $arg ? '1' : '0',
            default => (string) $arg,
        };
    }

    /**
     * Formats a float for the wire: the shortest plain decimal that round
     * trips to the same float, never an exponent, independent of locale
     * and of the precision ini settings, NaN rejected. A whole number
     * keeps a trailing ".0". See Support\Numbers::format(), the one
     * central formatter.
     */
    public static function formatFloat(float $n): string
    {
        return Numbers::format($n);
    }

    private function encodeBulk(string $s): string
    {
        return '$' . strlen($s) . "\r\n" . $s . "\r\n";
    }

    /** Sends every buffered command in one write and clears the buffer. */
    public function flush(): void
    {
        if ($this->buffer === '') {
            return;
        }
        $data = $this->buffer;
        $this->buffer = '';
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $chunk = @fwrite($this->stream, substr($data, $written));
            if ($chunk === false || ($chunk === 0 && $written < $total)) {
                $meta = @stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out'])) {
                    throw new TimeoutException('Timed out writing to the HotKV server.');
                }
                $error = error_get_last();
                throw new ConnectionException('Failed to write to the HotKV server' . ($error !== null ? ': ' . $error['message'] : '.'));
            }
            $written += $chunk;
        }
    }

    /** True when writeCommand() has buffered bytes not yet flush()ed. */
    public function hasPending(): bool
    {
        return $this->buffer !== '';
    }
}
