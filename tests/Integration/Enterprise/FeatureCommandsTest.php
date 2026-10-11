<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class FeatureCommandsTest extends IntegrationTestCase
{
    public function testCreateSetGetBatchSchemaListStatsDrop(): void
    {
        $this->withLicense(function (): void {
            $group = $this->ns('feature');
            self::assertSame('OK', $this->client->feature()->create($group, 'user features', [
                'age' => 'int64',
                'score' => 'double',
            ]));

            self::assertSame('OK', $this->client->feature()->set($group, 'user1', ['age' => 30, 'score' => 1.5]));

            $got = $this->client->feature()->get($group, 'user1');
            // Typed features come back as their native RESP3 type (int64 as
            // an integer here), not necessarily a string.
            self::assertEquals(30, $got['features']['age']);
            self::assertIsInt($got['updated_at_ms']);

            self::assertNull($this->client->feature()->get($group, 'missing-entity'));

            $batch = $this->client->feature()->batch($group, ['user1', 'missing-entity']);
            self::assertNotNull($batch[0]);
            self::assertNull($batch[1]);

            $schema = $this->client->feature()->schema($group);
            self::assertSame('int64', $schema['age']);

            self::assertContains($group, $this->client->feature()->list());

            $stats = $this->client->feature()->stats($group);
            self::assertSame(1, $stats['entities']);

            self::assertSame('OK', $this->client->feature()->drop($group));
        });
    }

    public function testSetOnMissingGroupErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->feature()->set($this->ns('missing'), 'e1', ['x' => 1]);
    }
}
