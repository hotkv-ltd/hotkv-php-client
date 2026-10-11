<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Support;

/**
 * A read-only stream wrapper that serves its buffer a few bytes at a
 * time, regardless of how much the caller asked for, so Reader's
 * multi-call read loops (readLine, readN) are exercised the same way a
 * slow socket would exercise them, instead of a php://memory stream that
 * always satisfies a whole fread() in one call.
 */
final class ChunkedStream
{
    private const PROTOCOL = 'hotkvchunked';

    private string $buffer = '';
    private int $position = 0;
    private int $chunkSize = 3;

    /** @var resource|null */
    public $context;

    public static function open(string $data, int $chunkSize = 3)
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $context = stream_context_create(['hotkvchunked' => ['data' => $data, 'chunkSize' => $chunkSize]]);

        return fopen(self::PROTOCOL . '://buffer', 'rb', false, $context);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $opts = stream_context_get_options($this->context)['hotkvchunked'] ?? [];
        $this->buffer = $opts['data'] ?? '';
        $this->chunkSize = $opts['chunkSize'] ?? 3;
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $take = min($count, $this->chunkSize, strlen($this->buffer) - $this->position);
        if ($take <= 0) {
            return '';
        }
        $chunk = substr($this->buffer, $this->position, $take);
        $this->position += $take;

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
