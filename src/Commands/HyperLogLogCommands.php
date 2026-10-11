<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

/**
 * HyperLogLog commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait HyperLogLogCommands
{
    /** @param list<string> $elements @return int 1 if the estimated cardinality changed */
    public function pfadd(string $key, array $elements): mixed
    {
        return $this->dispatch(array_merge(['PFADD', $key], $elements));
    }

    /** @param list<string> $keys @return int the approximated cardinality of the union of all given keys */
    public function pfcount(array $keys): mixed
    {
        return $this->dispatch(array_merge(['PFCOUNT'], $keys));
    }

    /** @param list<string> $sourceKeys @return string "OK" */
    public function pfmerge(string $destination, array $sourceKeys): mixed
    {
        return $this->dispatch(array_merge(['PFMERGE', $destination], $sourceKeys));
    }
}
