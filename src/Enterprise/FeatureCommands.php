<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Numbers;
use HotKV\Support\Reply;

/**
 * FEATURE.* : feature store (needs a server license that includes the
 * `feature-store` feature). Reach this via `$client->feature()`.
 */
final class FeatureCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /**
     * @param array<string, string> $schema feature name => type
     *     ("int64", "double"/"float", "string", "int_array",
     *     "double_array"/"float_array")
     * @return string "OK"
     */
    public function create(string $group, string $description, array $schema): mixed
    {
        $args = Args::new('FEATURE.CREATE', $group, $description)->push('SCHEMA');
        foreach ($schema as $name => $type) {
            $args->push((string) $name, $type);
        }

        return $this->client->execute($args->toArray());
    }

    /** @param array<string, string|int|float> $features @return string "OK" */
    public function set(string $group, string $entity, array $features): mixed
    {
        $args = ['FEATURE.SET', $group, $entity];
        foreach ($features as $name => $value) {
            $args[] = (string) $name;
            $args[] = is_float($value) ? Numbers::finite($value) : (string) $value;
        }

        return $this->client->execute($args);
    }

    /**
     * Omit $features to fetch every feature stored for the entity.
     *
     * @param list<string> $features
     * @return array{features: array<string, int|float|string>, updated_at_ms: int, freshness_ms: int}|null
     */
    public function get(string $group, string $entity, array $features = []): mixed
    {
        $reply = $this->client->execute(array_merge(['FEATURE.GET', $group, $entity], $features));

        return $reply === null ? null : self::mapRecord($reply);
    }

    /**
     * @param list<string> $entities
     * @param list<string> $features
     * @return list<array{features: array<string, int|float|string>, updated_at_ms: int, freshness_ms: int}|null>
     */
    public function batch(string $group, array $entities, array $features = []): mixed
    {
        $args = array_merge(['FEATURE.BATCH', $group], $entities);
        if ($features !== []) {
            $args[] = 'FEATURES';
            $args = array_merge($args, $features);
        }

        return array_map(
            static fn (?array $reply) => $reply === null ? null : self::mapRecord($reply),
            $this->client->execute($args),
        );
    }

    /** @return array<string, string> feature name => type */
    public function schema(string $group): mixed
    {
        return Reply::pairsToMap($this->client->execute(['FEATURE.SCHEMA', $group]));
    }

    /** @return list<string> group names */
    public function list(): mixed
    {
        return $this->client->execute(['FEATURE.LIST']);
    }

    /** @return string "OK" */
    public function drop(string $group): mixed
    {
        return $this->client->execute(['FEATURE.DROP', $group]);
    }

    /** @return array{entities: int, totalReads: int, totalWrites: int, memoryBytes: int} */
    public function stats(string $group): mixed
    {
        return Reply::toMap($this->client->execute(['FEATURE.STATS', $group]));
    }

    /** @return array{features: array<string, int|float|string>, updated_at_ms: int, freshness_ms: int} */
    private static function mapRecord(array $reply): array
    {
        return ['features' => Reply::toMap($reply[0]), 'updated_at_ms' => $reply[1], 'freshness_ms' => $reply[2]];
    }
}
