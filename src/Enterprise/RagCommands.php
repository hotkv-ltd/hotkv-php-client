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
 * RAG.* : retrieval-augmented generation pipelines (needs a server
 * license that includes the `rag-pipeline` feature). Reach this via
 * `$client->rag()`.
 */
final class RagCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return string "OK" */
    public function create(string $name, int $dimensions, string $strategy = 'fixed', int $chunkSize = 512, int $chunkOverlap = 64): mixed
    {
        $args = Args::new('RAG.CREATE', $name, $dimensions)
            ->push('STRATEGY', $strategy)->push('CHUNK_SIZE', $chunkSize)->push('CHUNK_OVERLAP', $chunkOverlap);

        return $this->client->execute($args->toArray());
    }

    /**
     * @param array<string, string> $meta
     * @param list<list<float>> $vectors one float vector per chunk produced from $text
     * @return int chunks ingested
     */
    public function ingest(string $pipeline, string $docId, string $text, array $meta = [], array $vectors = []): mixed
    {
        $args = Args::new('RAG.INGEST', $pipeline, $docId, $text);
        if ($meta !== []) {
            $args->push('META');
            foreach ($meta as $k => $v) {
                $args->push((string) $k, $v);
            }
        }
        if ($vectors !== []) {
            $args->push('VECTORS');
            foreach ($vectors as $vector) {
                $args->push(implode(',', array_map(Numbers::finite(...), $vector)));
            }
        }

        return $this->client->execute($args->toArray());
    }

    /**
     * @param array<string, string> $filter
     * @return list<array{chunk_id: string, doc_id: string, text: string,
     *     score: string, vector_score: string, bm25_score: string, meta: array<string, string>}>
     */
    public function search(string $pipeline, string $queryText, int $k = 5, float $alpha = 0.5, ?array $vector = null, array $filter = []): mixed
    {
        $args = Args::new('RAG.SEARCH', $pipeline, $queryText)->push('K', $k)->push('ALPHA', Numbers::finite($alpha));
        if ($vector !== null) {
            $args->push('VECTOR', implode(',', array_map(Numbers::finite(...), $vector)));
        }
        if ($filter !== []) {
            $args->push('FILTER');
            foreach ($filter as $k2 => $v2) {
                $args->push((string) $k2, $v2);
            }
        }

        return array_map(self::mapSearchRow(...), $this->client->execute($args->toArray()));
    }

    /**
     * @return array{name: string, dimensions: int, totalDocs: int,
     *     totalChunks: int, totalIngested: int, totalQueries: int,
     *     chunkSize: int, chunkStrategy: string, chunkOverlap: int}
     */
    public function stats(string $pipeline): mixed
    {
        return Reply::toMap($this->client->execute(['RAG.STATS', $pipeline]));
    }

    /** @return string "OK" */
    public function drop(string $pipeline): mixed
    {
        return $this->client->execute(['RAG.DROP', $pipeline]);
    }

    /** @return list<string> */
    public function list(): mixed
    {
        return $this->client->execute(['RAG.LIST']);
    }

    /** @return array{chunk_id: string, doc_id: string, text: string, score: string, vector_score: string, bm25_score: string, meta: array<string, string>} */
    private static function mapSearchRow(array $row): array
    {
        $meta = [];
        for ($i = 6, $n = count($row); $i + 1 < $n; $i += 2) {
            $meta[(string) $row[$i]] = $row[$i + 1];
        }

        return [
            'chunk_id' => $row[0], 'doc_id' => $row[1], 'text' => $row[2],
            'score' => $row[3], 'vector_score' => $row[4], 'bm25_score' => $row[5],
            'meta' => $meta,
        ];
    }
}
