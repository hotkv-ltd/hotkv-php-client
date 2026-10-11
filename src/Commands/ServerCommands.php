<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;
use HotKV\Support\Reply;

/**
 * Server and connection administration commands. Requires the composing
 * class to implement HotKV\CommandDispatcher.
 */
trait ServerCommands
{
    use NormalizesReplies;

    /** @return string "PONG", or $message when given */
    public function ping(?string $message = null): mixed
    {
        return $this->dispatch($message === null ? ['PING'] : ['PING', $message]);
    }

    public function echo(string $message): mixed
    {
        return $this->dispatch(['ECHO', $message]);
    }

    public function info(?string $section = null): mixed
    {
        return $this->dispatch($section === null ? ['INFO'] : ['INFO', $section]);
    }

    public function dbsize(): mixed
    {
        return $this->dispatch(['DBSIZE']);
    }

    /** @return string "OK" */
    public function flushdb(bool $async = false): mixed
    {
        return $this->dispatch($async ? ['FLUSHDB', 'ASYNC'] : ['FLUSHDB']);
    }

    /** @return string "OK" */
    public function flushall(bool $async = false): mixed
    {
        return $this->dispatch($async ? ['FLUSHALL', 'ASYNC'] : ['FLUSHALL']);
    }

    /** @return array{0: string, 1: string} [unixSeconds, microseconds] */
    public function time(): mixed
    {
        return $this->dispatch(['TIME']);
    }

    /** @return array<string, string> */
    public function configGet(string $pattern = '*'): mixed
    {
        return $this->normalized($this->dispatch(['CONFIG', 'GET', $pattern]), Reply::toMap(...));
    }

    /** @param array<string, string> $pairs @return string "OK" */
    public function configSet(array $pairs): mixed
    {
        $args = ['CONFIG', 'SET'];
        foreach ($pairs as $k => $v) {
            $args[] = (string) $k;
            $args[] = $v;
        }

        return $this->dispatch($args);
    }

    /** @return string "OK" */
    public function clientSetname(string $name): mixed
    {
        return $this->dispatch(['CLIENT', 'SETNAME', $name]);
    }

    public function clientGetname(): mixed
    {
        return $this->dispatch(['CLIENT', 'GETNAME']);
    }

    public function clientId(): mixed
    {
        return $this->dispatch(['CLIENT', 'ID']);
    }

    /**
     * Changes the selected database on the current connection. Note this
     * only affects the live connection: after a reconnect the client
     * returns to the database configured in ClientOptions.
     *
     * @return string "OK"
     */
    public function select(int $database): mixed
    {
        return $this->dispatch(['SELECT', $database]);
    }

    /** @return string "OK" */
    public function swapdb(int $db1, int $db2): mixed
    {
        return $this->dispatch(['SWAPDB', $db1, $db2]);
    }

    public function lastsave(): mixed
    {
        return $this->dispatch(['LASTSAVE']);
    }

    /** @return list<mixed> ["master", offset, [...replicas]] or ["slave", host, port, state, offset] */
    public function role(): mixed
    {
        return $this->dispatch(['ROLE']);
    }

    /**
     * WAIT: blocks until $numReplicas replicas acknowledged this
     * connection's writes, or $timeoutMs elapsed (0 = forever).
     *
     * @return int replicas that acknowledged
     */
    public function wait(int $numReplicas, int $timeoutMs): mixed
    {
        return $this->dispatch(['WAIT', $numReplicas, $timeoutMs]);
    }

    /**
     * @return list<list<mixed>> entries [id, unix time, microseconds taken, [command args], client address, client name]
     */
    public function slowlogGet(?int $count = null): mixed
    {
        return $this->dispatch($count === null ? ['SLOWLOG', 'GET'] : ['SLOWLOG', 'GET', $count]);
    }

    public function slowlogLen(): mixed
    {
        return $this->dispatch(['SLOWLOG', 'LEN']);
    }

    /** @return string "OK" */
    public function slowlogReset(): mixed
    {
        return $this->dispatch(['SLOWLOG', 'RESET']);
    }

    /** @return int|null bytes used by the key and its value, or null if the key does not exist */
    public function memoryUsage(string $key, ?int $samples = null): mixed
    {
        return $this->dispatch($samples === null ? ['MEMORY', 'USAGE', $key] : ['MEMORY', 'USAGE', $key, 'SAMPLES', $samples]);
    }
}
