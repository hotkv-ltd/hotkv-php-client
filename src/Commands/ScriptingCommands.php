<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

/**
 * Lua scripting commands. Requires the composing class to implement
 * HotKV\CommandDispatcher. See Client::evalOrLoad() for a EVALSHA-with-
 * NOSCRIPT-fallback helper, which needs the real, synchronous reply and
 * so is only available directly on Client (not Pipeline/Transaction).
 */
trait ScriptingCommands
{
    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->dispatch(array_merge(['EVAL', $script, count($keys)], $keys, $args));
    }

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function evalsha(string $sha1, array $keys = [], array $args = []): mixed
    {
        return $this->dispatch(array_merge(['EVALSHA', $sha1, count($keys)], $keys, $args));
    }

    /** @return string the script's SHA1 digest */
    public function scriptLoad(string $script): mixed
    {
        return $this->dispatch(['SCRIPT', 'LOAD', $script]);
    }

    /** @param list<string> $shas @return list<int> 0/1 per SHA1 */
    public function scriptExists(array $shas): mixed
    {
        return $this->dispatch(array_merge(['SCRIPT', 'EXISTS'], $shas));
    }

    /** @return string "OK" */
    public function scriptFlush(): mixed
    {
        return $this->dispatch(['SCRIPT', 'FLUSH']);
    }

    /**
     * EVAL_RO: like eval(), but the script may not write.
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function evalRo(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->dispatch(array_merge(['EVAL_RO', $script, count($keys)], $keys, $args));
    }

    /**
     * EVALSHA_RO: like evalsha(), but the script may not write.
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function evalshaRo(string $sha1, array $keys = [], array $args = []): mixed
    {
        return $this->dispatch(array_merge(['EVALSHA_RO', $sha1, count($keys)], $keys, $args));
    }
}
