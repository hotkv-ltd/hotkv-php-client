<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;

/**
 * Bitmap commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait BitmapCommands
{
    /** @return int the bit's previous value */
    public function setbit(string $key, int $offset, int $value): mixed
    {
        return $this->dispatch(['SETBIT', $key, $offset, $value]);
    }

    public function getbit(string $key, int $offset): mixed
    {
        return $this->dispatch(['GETBIT', $key, $offset]);
    }

    /** @param bool $bit when true, $start/$end count bits instead of bytes */
    public function bitcount(string $key, ?int $start = null, ?int $end = null, bool $bit = false): mixed
    {
        $args = Args::new('BITCOUNT', $key);
        if ($start !== null && $end !== null) {
            $args->push($start, $end)->flag('BIT', $bit);
        }

        return $this->dispatch($args->toArray());
    }

    /** @return int the position of the first bit set to $bitValue, or -1 if not found */
    public function bitpos(string $key, int $bitValue, ?int $start = null, ?int $end = null, bool $bit = false): mixed
    {
        $args = Args::new('BITPOS', $key, $bitValue);
        if ($start !== null) {
            $args->push($start);
            if ($end !== null) {
                $args->push($end)->flag('BIT', $bit);
            }
        }

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $keys @return int the destination string's length */
    public function bitop(string $operation, string $destination, array $keys): mixed
    {
        return $this->dispatch(array_merge(['BITOP', strtoupper($operation), $destination], $keys));
    }

    /**
     * Runs a raw BITFIELD sub-command sequence, e.g.
     * `bitfield('k', ['SET', 'u8', 0, 255, 'GET', 'u8', 0])`.
     *
     * @param list<string|int> $operations
     * @return list<int|null>
     */
    public function bitfield(string $key, array $operations): mixed
    {
        return $this->dispatch(array_merge(['BITFIELD', $key], $operations));
    }

    /** Read-only BITFIELD: only GET sub-operations are allowed. @param list<string|int> $operations */
    public function bitfieldRo(string $key, array $operations): mixed
    {
        return $this->dispatch(array_merge(['BITFIELD_RO', $key], $operations));
    }
}
