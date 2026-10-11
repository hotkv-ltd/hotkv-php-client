<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Protocol;

use HotKV\Exception\ConnectionException;
use HotKV\Exception\ProtocolException;
use HotKV\Exception\TimeoutException;

/**
 * Decodes RESP2 and RESP3 values from a PHP stream resource.
 *
 * One reader wraps one connection's socket. readValue() decodes exactly
 * one complete value (recursing into nested containers) and never throws
 * for an error reply: it returns a ReplyError instead, so callers such as
 * Pipeline can surface a per-command error without aborting the batch.
 * Transport failures (closed connection, timeout, malformed framing) do
 * throw, since there is no value to hand back in that case.
 */
final class Reader
{
    private const MAX_BULK_LEN = 512 * 1024 * 1024;
    private const MAX_ARRAY_LEN = 1024 * 1024;
    private const MAX_NEST_DEPTH = 64;
    private const MAX_LINE_LEN = 1024 * 1024;

    /** @var resource */
    private $stream;

    /** @param resource $stream */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Reads and decodes exactly one RESP value. Returns a native PHP type
     * for every wire type: null, bool, int, float, string, list array,
     * associative array (map), ReplyError, BigNumber or PushMessage.
     */
    public function readValue(): mixed
    {
        return $this->decode(0);
    }

    private function decode(int $depth): mixed
    {
        if ($depth > self::MAX_NEST_DEPTH) {
            throw new ProtocolException('RESP nesting depth exceeded ' . self::MAX_NEST_DEPTH);
        }

        $type = $this->readN(1);

        return match ($type) {
            '+' => $this->readLine(),
            '-' => new ReplyError($this->readLine(), false),
            ':' => $this->readInt(),
            '$' => $this->readBulk(),
            '*' => $this->readArray($depth),
            '_' => $this->readNull(),
            ',' => $this->readDouble(),
            '#' => $this->readBool(),
            '!' => new ReplyError($this->readBlob(), true),
            '=' => $this->decodeVerbatim($this->readBlob()),
            '(' => $this->readBigNumber(),
            '%' => $this->readMap($depth),
            '~' => $this->readSet($depth),
            '|' => $this->readAttribute($depth),
            '>' => $this->readPush($depth),
            default => throw new ProtocolException(sprintf(
                'Unexpected RESP type byte 0x%02X',
                $type === '' ? 0 : ord($type),
            )),
        };
    }

    private function readInt(): int
    {
        $line = $this->readLine();
        if ($line === '' || preg_match('/^-?\d+$/', $line) !== 1) {
            throw new ProtocolException("Invalid RESP integer: \"{$line}\"");
        }

        return (int) $line;
    }

    private function readNull(): mixed
    {
        // RESP3 null is "_\r\n": nothing but the terminator follows.
        $line = $this->readLine();
        if ($line !== '') {
            throw new ProtocolException("Unexpected data after RESP3 null: \"{$line}\"");
        }

        return null;
    }

    private function readDouble(): float
    {
        $line = $this->readLine();

        return match ($line) {
            'inf', '+inf' => INF,
            '-inf' => -INF,
            'nan' => NAN,
            default => is_numeric($line)
                ? (float) $line
                : throw new ProtocolException("Invalid RESP double: \"{$line}\""),
        };
    }

    private function readBool(): bool
    {
        $line = $this->readLine();

        return match ($line) {
            't' => true,
            'f' => false,
            default => throw new ProtocolException("Invalid RESP boolean: \"{$line}\""),
        };
    }

    private function readBigNumber(): BigNumber
    {
        $line = $this->readLine();
        if (preg_match('/^-?\d+$/', $line) !== 1) {
            throw new ProtocolException("Invalid RESP big number: \"{$line}\"");
        }

        return new BigNumber($line);
    }

    private function readBulk(): ?string
    {
        $length = $this->readLength();
        if ($length === null) {
            return null;
        }

        return $this->readPayload($length);
    }

    private function readBlob(): string
    {
        $length = $this->readLength();
        if ($length === null) {
            throw new ProtocolException('RESP blob error may not have a null length.');
        }

        return $this->readPayload($length);
    }

    private function decodeVerbatim(string $content): string
    {
        // Format is "xxx:payload": a 3 byte type code, a colon, then the
        // actual text. Strip the prefix; callers only want the text.
        if (strlen($content) >= 4 && $content[3] === ':') {
            return substr($content, 4);
        }

        return $content;
    }

    private function readLength(): ?int
    {
        $line = $this->readLine();
        if ($line === '-1') {
            return null;
        }
        if (preg_match('/^\d+$/', $line) !== 1) {
            throw new ProtocolException("Invalid RESP bulk length: \"{$line}\"");
        }
        $length = (int) $line;
        if ($length > self::MAX_BULK_LEN) {
            throw new ProtocolException("RESP bulk length {$length} exceeds the maximum allowed.");
        }

        return $length;
    }

    private function readPayload(int $length): string
    {
        if ($length === 0) {
            $tail = $this->readN(2);
            if ($tail !== "\r\n") {
                throw new ProtocolException('Missing CRLF after RESP bulk payload.');
            }

            return '';
        }

        $buf = $this->readN($length + 2);
        if (substr($buf, -2) !== "\r\n") {
            throw new ProtocolException('Missing CRLF after RESP bulk payload.');
        }

        return substr($buf, 0, $length);
    }

    /** @return list<mixed>|null */
    private function readArray(int $depth): ?array
    {
        $count = $this->readCount(self::MAX_ARRAY_LEN);
        if ($count === null) {
            return null;
        }
        $elems = [];
        for ($i = 0; $i < $count; $i++) {
            $elems[] = $this->decode($depth + 1);
        }

        return $elems;
    }

    /** @return list<mixed> */
    private function readSet(int $depth): array
    {
        return $this->readArray($depth) ?? [];
    }

    /** @return array<array-key, mixed> */
    private function readMap(int $depth): array
    {
        $count = $this->readCount((int) (self::MAX_ARRAY_LEN / 2)) ?? 0;
        $map = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $this->decode($depth + 1);
            $value = $this->decode($depth + 1);
            $map[$this->mapKey($key)] = $value;
        }

        return $map;
    }

    private function mapKey(mixed $key): string|int
    {
        return match (true) {
            is_string($key), is_int($key) => $key,
            is_float($key) => rtrim(rtrim(sprintf('%.17G', $key), '0'), '.'),
            is_bool($key) => $key ? '1' : '0',
            default => throw new ProtocolException('RESP map key is not a scalar value.'),
        };
    }

    private function readAttribute(int $depth): mixed
    {
        // Attributes are metadata preceding the actual reply. The SDK has
        // no consumer for them yet, so decode and discard the pairs, then
        // decode and return the value that follows, transparently.
        $count = $this->readCount((int) (self::MAX_ARRAY_LEN / 2)) ?? 0;
        for ($i = 0; $i < $count; $i++) {
            $this->decode($depth + 1);
            $this->decode($depth + 1);
        }

        return $this->decode($depth);
    }

    private function readPush(int $depth): PushMessage
    {
        return new PushMessage($this->readArray($depth) ?? []);
    }

    private function readCount(int $max): ?int
    {
        $line = $this->readLine();
        if ($line === '-1') {
            return null;
        }
        if (preg_match('/^\d+$/', $line) !== 1) {
            throw new ProtocolException("Invalid RESP element count: \"{$line}\"");
        }
        $count = (int) $line;
        if ($count > $max) {
            throw new ProtocolException("RESP element count {$count} exceeds the maximum allowed.");
        }

        return $count;
    }

    private function readLine(): string
    {
        $line = '';
        while (true) {
            $chunk = @fgets($this->stream, 8192);
            if ($chunk === false) {
                $this->throwOnFailedRead();
            }
            $line .= $chunk;
            if (str_ends_with($line, "\n")) {
                break;
            }
            if (strlen($line) > self::MAX_LINE_LEN) {
                throw new ProtocolException('RESP line exceeded the maximum allowed length.');
            }
        }
        $len = strlen($line);
        if ($len < 2 || $line[$len - 2] !== "\r") {
            throw new ProtocolException('Invalid RESP line terminator.');
        }

        return substr($line, 0, $len - 2);
    }

    private function readN(int $length): string
    {
        if ($length === 0) {
            return '';
        }
        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($this->stream, $remaining);
            if ($chunk === false || $chunk === '') {
                $this->throwOnFailedRead();
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    private function throwOnFailedRead(): never
    {
        $meta = @stream_get_meta_data($this->stream);
        if (!empty($meta['timed_out'])) {
            throw new TimeoutException('Timed out reading from the HotKV server.');
        }
        if (feof($this->stream)) {
            throw new ConnectionException('Connection to the HotKV server was closed.');
        }
        $error = error_get_last();
        throw new ConnectionException('Failed to read from the HotKV server' . ($error !== null ? ': ' . $error['message'] : '.'));
    }
}
