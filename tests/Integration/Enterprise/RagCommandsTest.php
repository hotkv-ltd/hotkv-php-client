<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class RagCommandsTest extends IntegrationTestCase
{
    public function testCreateIngestSearchStatsAndDrop(): void
    {
        $this->withLicense(function (): void {
            $pipeline = $this->ns('rag');
            self::assertSame('OK', $this->client->rag()->create($pipeline, 3, chunkSize: 100, chunkOverlap: 0));

            $chunks = $this->client->rag()->ingest(
                $pipeline,
                'doc1',
                'hotkv is fast',
                meta: ['source' => 'unit-test'],
                vectors: [[1.0, 0.0, 0.0]],
            );
            self::assertSame(1, $chunks);

            $results = $this->client->rag()->search($pipeline, 'hotkv', k: 5, vector: [1.0, 0.0, 0.0]);
            self::assertNotEmpty($results);
            self::assertSame('doc1', $results[0]['doc_id']);
            self::assertSame('unit-test', $results[0]['meta']['source']);

            $stats = $this->client->rag()->stats($pipeline);
            self::assertSame($pipeline, $stats['name']);
            self::assertSame(1, $stats['totalDocs']);

            self::assertContains($pipeline, $this->client->rag()->list());
            self::assertSame('OK', $this->client->rag()->drop($pipeline));
        });
    }

    public function testIngestWithoutCreateErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->rag()->ingest($this->ns('missing-pipeline'), 'doc1', 'text');
    }
}
