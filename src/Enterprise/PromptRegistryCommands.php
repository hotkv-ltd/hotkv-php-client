<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;

/**
 * PROMPT.* : versioned prompt/template registry (needs a server license
 * that includes the `prompt-registry` feature). Reach this via
 * `$client->prompt()`. Distinct from HotKV\AI\PromptCache, which caches
 * LLM responses rather than storing prompt templates.
 */
final class PromptRegistryCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return int the new version number (versions start at 1) */
    public function put(string $name, string $content, ?string $meta = null): mixed
    {
        $args = Args::new('PROMPT.PUT', $name, $content)->opt('META', $meta);

        return $this->client->execute($args->toArray());
    }

    /**
     * Omitting $version (or passing 0) returns the latest version.
     *
     * @return array{0: int, 1: string, 2: string, 3: int}|null [version, content, metadata, created_unix]
     */
    public function get(string $name, ?int $version = null): mixed
    {
        return $this->client->execute($version === null ? ['PROMPT.GET', $name] : ['PROMPT.GET', $name, $version]);
    }

    /** Alias for get($name) with no version. @return array{0: int, 1: string, 2: string, 3: int}|null */
    public function latest(string $name): mixed
    {
        return $this->client->execute(['PROMPT.LATEST', $name]);
    }

    /** @return string "OK" */
    public function setActive(string $name, int $version): mixed
    {
        return $this->client->execute(['PROMPT.SETACTIVE', $name, $version]);
    }

    /** @return list<int> every stored version number */
    public function versions(string $name): mixed
    {
        return $this->client->execute(['PROMPT.VERSIONS', $name]);
    }

    /** @return int 1/0 */
    public function drop(string $name): mixed
    {
        return $this->client->execute(['PROMPT.DROP', $name]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['PROMPT.LIST']);
    }
}
