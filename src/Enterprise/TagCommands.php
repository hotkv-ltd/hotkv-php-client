<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;

/**
 * TAG.* : key tagging. Works without a license. Reach this via
 * `$client->tag()`.
 */
final class TagCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @param list<string> $tags @return int tags newly added */
    public function set(string $key, array $tags): mixed
    {
        return $this->client->execute(array_merge(['TAG.SET', $key], $tags));
    }

    /** @param list<string> $tags @return int tags removed */
    public function rem(string $key, array $tags): mixed
    {
        return $this->client->execute(array_merge(['TAG.REM', $key], $tags));
    }

    /** @return list<string> every tag on $key */
    public function get(string $key): mixed
    {
        return $this->client->execute(['TAG.GET', $key]);
    }

    /** @return list<string> every key carrying $tag */
    public function members(string $tag): mixed
    {
        return $this->client->execute(['TAG.MEMBERS', $tag]);
    }

    /** @param list<string> $tags @return list<string> keys carrying every given tag */
    public function inter(array $tags): mixed
    {
        return $this->client->execute(array_merge(['TAG.INTER'], $tags));
    }

    public function count(string $key): mixed
    {
        return $this->client->execute(['TAG.COUNT', $key]);
    }

    /** @return int 0/1 */
    public function exists(string $key, string $tag): mixed
    {
        return $this->client->execute(['TAG.EXISTS', $key, $tag]);
    }
}
