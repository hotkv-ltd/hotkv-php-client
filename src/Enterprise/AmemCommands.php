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
 * AMEM.* : AI agent memory (needs a server license that includes the
 * `agent-memory` feature; AMEM.SUMMARY.* additionally needs the
 * `amem-summary` feature). Reach this via `$client->amem()`.
 *
 * Grouped by memory kind: conv* (per-session conversation window),
 * sem* (per-agent semantic vector store), work* (per-session scratch
 * key/value), epis* (per-agent append-only event log), search()
 * (cross-memory search) and summary* (per-agent rolling summary).
 */
final class AmemCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    // -- Conversation memory --

    /** @return string "OK" */
    public function convCreate(string $sessionId, ?int $window = null, ?int $ttl = null): mixed
    {
        $args = Args::new('AMEM.CONV.CREATE', $sessionId)->opt('WINDOW', $window)->opt('TTL', $ttl);

        return $this->client->execute($args->toArray());
    }

    /** @return string "OK" */
    public function convAppend(string $sessionId, string $role, string $content): mixed
    {
        return $this->client->execute(['AMEM.CONV.APPEND', $sessionId, $role, $content]);
    }

    /** @return list<array{role: string, content: string}> */
    public function convGet(string $sessionId): mixed
    {
        return self::mapTurns($this->client->execute(['AMEM.CONV.GET', $sessionId]));
    }

    /** @return list<array{role: string, content: string}> the last $n turns */
    public function convWindow(string $sessionId, int $n): mixed
    {
        return self::mapTurns($this->client->execute(['AMEM.CONV.WINDOW', $sessionId, $n]));
    }

    /** @return int 1/0 */
    public function convClear(string $sessionId): mixed
    {
        return $this->client->execute(['AMEM.CONV.CLEAR', $sessionId]);
    }

    /** @return int 1/0 */
    public function convDrop(string $sessionId): mixed
    {
        return $this->client->execute(['AMEM.CONV.DROP', $sessionId]);
    }

    // -- Semantic memory (per-agent vector store) --

    /** @return string "OK" */
    public function semCreate(string $agentId, int $dimensions): mixed
    {
        return $this->client->execute(['AMEM.SEM.CREATE', $agentId, $dimensions]);
    }

    /** @param list<float> $vector @return string "OK" */
    public function semStore(string $agentId, string $key, string $content, array $vector, ?int $ttl = null): mixed
    {
        $args = Args::new('AMEM.SEM.STORE', $agentId, $key, $content)->pushAll(array_map(Numbers::finite(...), $vector));
        $args->opt('TTL', $ttl);

        return $this->client->execute($args->toArray());
    }

    /** @param list<float> $vector @return list<array{key: string, content: string, similarity: string}> most similar first */
    public function semRecall(string $agentId, int $k, array $vector): mixed
    {
        $args = Args::new('AMEM.SEM.RECALL', $agentId, $k)->pushAll(array_map(Numbers::finite(...), $vector));

        return array_map(Reply::toMap(...), $this->client->execute($args->toArray()));
    }

    /** @return int 1/0 */
    public function semForget(string $agentId, string $key): mixed
    {
        return $this->client->execute(['AMEM.SEM.FORGET', $agentId, $key]);
    }

    /** @return list<string> keys */
    public function semList(string $agentId): mixed
    {
        return $this->client->execute(['AMEM.SEM.LIST', $agentId]);
    }

    // -- Working memory (per-session scratch key/value) --

    /** @return string "OK" */
    public function workSet(string $sessionId, string $key, string $value, ?int $ttl = null): mixed
    {
        $args = Args::new('AMEM.WORK.SET', $sessionId, $key, $value)->opt('TTL', $ttl);

        return $this->client->execute($args->toArray());
    }

    /** @return string|null note: an empty stored value is indistinguishable from a miss */
    public function workGet(string $sessionId, string $key): mixed
    {
        return $this->client->execute(['AMEM.WORK.GET', $sessionId, $key]);
    }

    /** @return int 1/0 */
    public function workDel(string $sessionId, string $key): mixed
    {
        return $this->client->execute(['AMEM.WORK.DEL', $sessionId, $key]);
    }

    /** @return int 1/0 */
    public function workClear(string $sessionId): mixed
    {
        return $this->client->execute(['AMEM.WORK.CLEAR', $sessionId]);
    }

    /** @return list<string> keys */
    public function workList(string $sessionId): mixed
    {
        return $this->client->execute(['AMEM.WORK.LIST', $sessionId]);
    }

    // -- Episodic memory (append-only event log) --

    /** @return string "OK" */
    public function episLog(string $agentId, string $eventType, string $content, ?string $metadata = null): mixed
    {
        $args = Args::new('AMEM.EPIS.LOG', $agentId, $eventType, $content)->opt('METADATA', $metadata);

        return $this->client->execute($args->toArray());
    }

    /** @return list<array{type: string, content: string, metadata: string, timestamp: int}> */
    public function episQuery(string $agentId, ?string $type = null, ?int $from = null, ?int $to = null, ?int $limit = null): mixed
    {
        $args = Args::new('AMEM.EPIS.QUERY', $agentId)->opt('TYPE', $type)->opt('FROM', $from)->opt('TO', $to)->opt('LIMIT', $limit);

        return array_map(Reply::toMap(...), $this->client->execute($args->toArray()));
    }

    /** @return int 1/0 */
    public function episClear(string $agentId): mixed
    {
        return $this->client->execute(['AMEM.EPIS.CLEAR', $agentId]);
    }

    /** @return array{total_events: int, oldest_ts: int, newest_ts: int} */
    public function episStats(string $agentId): mixed
    {
        return Reply::toMap($this->client->execute(['AMEM.EPIS.STATS', $agentId]));
    }

    // -- Cross-memory search (semantic + episodic + working together) --

    /** @param list<float> $vector @return list<array{source: string, key: string, content: string, similarity: string}> */
    public function search(string $agentId, int $k, array $vector): mixed
    {
        $args = Args::new('AMEM.SEARCH', $agentId, $k)->pushAll(array_map(Numbers::finite(...), $vector));

        return array_map(Reply::toMap(...), $this->client->execute($args->toArray()));
    }

    // -- Rolling summary (needs the `amem-summary` license feature) --

    /** @return int the new version number */
    public function summarySet(string $agentId, string $summary): mixed
    {
        return $this->client->execute(['AMEM.SUMMARY.SET', $agentId, $summary]);
    }

    /** Appends to the stored summary text; the server joins it to the existing text with a newline. @return int the new version number */
    public function summaryAppend(string $agentId, string $extra): mixed
    {
        return $this->client->execute(['AMEM.SUMMARY.APPEND', $agentId, $extra]);
    }

    /** @return array{0: string, 1: int, 2: int}|null [summary, version, updated_unix] */
    public function summaryGet(string $agentId): mixed
    {
        return $this->client->execute(['AMEM.SUMMARY.GET', $agentId]);
    }

    /** @return int 1/0 */
    public function summaryDrop(string $agentId): mixed
    {
        return $this->client->execute(['AMEM.SUMMARY.DROP', $agentId]);
    }

    /** @return list<string> agent ids */
    public function summaryList(): mixed
    {
        return $this->client->execute(['AMEM.SUMMARY.LIST']);
    }

    /** @return list<array{role: string, content: string}> */
    private static function mapTurns(?array $turns): array
    {
        return array_map(Reply::toMap(...), $turns ?? []);
    }
}
