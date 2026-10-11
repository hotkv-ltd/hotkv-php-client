<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Reply;

/**
 * VSIM.* : vector similarity index. Works without a license except
 * persistence (SAVE/LOAD/LIST/DROP), which needs a server license that
 * includes the `vsim-persist` feature. Reach this via `$client->vsim()`.
 */
final class VsimCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function createIndex(
        string $name,
        int $dims,
        string $metric = 'cosine',
        int $m = 16,
        int $efConstruction = 200,
        int $efSearch = 50,
    ): mixed {
        $args = Args::new('VSIM.CREATEINDEX', $name, $dims)
            ->push('METRIC', $metric)->push('M', $m)->push('EF_CONSTRUCTION', $efConstruction)->push('EF_SEARCH', $efSearch);

        return $this->client->execute($args->toArray());
    }

    /** @return string "OK" */
    public function dropIndex(string $name): mixed
    {
        return $this->client->execute(['VSIM.DROPINDEX', $name]);
    }

    /** @param list<float> $vector @return int 1 if newly inserted, 0 if it replaced an existing vector */
    public function set(string $index, string $key, array $vector): mixed
    {
        return $this->client->execute(array_merge(['VSIM.SET', $index, $key], self::floats($vector)));
    }

    /** @return list<string>|null vector components formatted "%.8g", or null if $key is not in the index */
    public function get(string $index, string $key): mixed
    {
        return $this->client->execute(['VSIM.GET', $index, $key]);
    }

    /** @return int 1/0 */
    public function del(string $index, string $key): mixed
    {
        return $this->client->execute(['VSIM.DEL', $index, $key]);
    }

    /**
     * @param list<float> $vector
     * @param list<string> $filterTags restricts results to keys carrying every given tag (via TAG.INTER)
     * @return array<string, string> key => distance ("%.8g"), nearest first
     */
    public function search(string $index, int $k, array $vector, array $filterTags = [], ?int $ef = null): mixed
    {
        $args = Args::new('VSIM.SEARCH', $index, $k)->pushAll(self::floats($vector));
        if ($filterTags !== []) {
            $args->push('FILTER')->pushAll($filterTags);
        }
        $args->opt('EF', $ef);

        return Reply::toMap($this->client->execute($args->toArray()));
    }

    /**
     * @return list<string>|array{name: string, dimensions: int, metric: string, vectors: int, M: int, ef_construction: int, ef_search: int}
     *     index names when $index is omitted, else that index's info
     */
    public function info(?string $index = null): mixed
    {
        $reply = $this->client->execute($index === null ? ['VSIM.INFO'] : ['VSIM.INFO', $index]);

        return $index === null ? $reply : Reply::toMap($reply);
    }

    // -- Persistence (needs the `vsim-persist` license feature) --

    /** @return int bytes written */
    public function save(string $snapshotName, string $blob): mixed
    {
        return $this->client->execute(['VSIM.SAVE', $snapshotName, $blob]);
    }

    /** @return string|null the blob, or null if not found */
    public function load(string $snapshotName): mixed
    {
        return $this->client->execute(['VSIM.LOAD', $snapshotName]);
    }

    /** @return list<string> snapshot names */
    public function list(): mixed
    {
        return $this->client->execute(['VSIM.LIST']);
    }

    /** @return int 1/0 */
    public function drop(string $snapshotName): mixed
    {
        return $this->client->execute(['VSIM.DROP', $snapshotName]);
    }

    /** @param list<float> $vector @return list<string> */
    private static function floats(array $vector): array
    {
        return array_map(\HotKV\Support\Numbers::finite(...), $vector);
    }
}
