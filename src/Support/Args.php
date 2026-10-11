<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Support;

/**
 * A small fluent builder for command argument lists. Most HotKV commands
 * are a handful of fixed positional arguments followed by optional
 * "KEYWORD value" pairs; this keeps that pattern readable and guarantees
 * options are only appended, never interleaved, matching how the server
 * scans them left to right.
 */
final class Args
{
    /** @var list<string|int|float|bool> */
    private array $parts = [];

    /** @param string|int|float|bool ...$base */
    public static function new(...$base): self
    {
        $args = new self();
        foreach ($base as $value) {
            $args->parts[] = $value;
        }

        return $args;
    }

    public function push(string|int|float|bool ...$values): static
    {
        foreach ($values as $value) {
            $this->parts[] = $value;
        }

        return $this;
    }

    /** Appends a repeated list of values (e.g. every tag in TAG.SET). */
    public function pushAll(array $values): static
    {
        foreach ($values as $value) {
            $this->parts[] = $value;
        }

        return $this;
    }

    /** Appends "$keyword $value" only when $value is not null. */
    public function opt(string $keyword, string|int|float|bool|null $value): static
    {
        if ($value !== null) {
            $this->parts[] = $keyword;
            $this->parts[] = $value;
        }

        return $this;
    }

    /** Appends the bare keyword only when $present is true. */
    public function flag(string $keyword, bool $present): static
    {
        if ($present) {
            $this->parts[] = $keyword;
        }

        return $this;
    }

    /** @return list<string|int|float|bool> */
    public function toArray(): array
    {
        return $this->parts;
    }
}
