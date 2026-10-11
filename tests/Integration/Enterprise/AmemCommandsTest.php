<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class AmemCommandsTest extends IntegrationTestCase
{
    public function testConversationMemory(): void
    {
        $this->withLicense(function (): void {
            $session = $this->ns('conv');
            self::assertSame('OK', $this->client->amem()->convCreate($session, window: 10));
            self::assertSame('OK', $this->client->amem()->convAppend($session, 'user', 'hello'));
            self::assertSame('OK', $this->client->amem()->convAppend($session, 'assistant', 'hi there'));

            $turns = $this->client->amem()->convGet($session);
            self::assertCount(2, $turns);
            self::assertSame('user', $turns[0]['role']);
            self::assertSame('hello', $turns[0]['content']);

            $window = $this->client->amem()->convWindow($session, 1);
            self::assertCount(1, $window);
            self::assertSame('assistant', $window[0]['role']);

            self::assertSame(1, $this->client->amem()->convClear($session));
            self::assertSame(1, $this->client->amem()->convDrop($session));
        });
    }

    public function testConvAppendOnMissingSessionErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->amem()->convAppend($this->ns('missing'), 'user', 'x');
    }

    public function testSemanticMemory(): void
    {
        $this->withLicense(function (): void {
            $agent = $this->ns('sem-agent');
            self::assertSame('OK', $this->client->amem()->semCreate($agent, 3));
            self::assertSame('OK', $this->client->amem()->semStore($agent, 'fact1', 'the sky is blue', [1.0, 0.0, 0.0]));
            self::assertSame('OK', $this->client->amem()->semStore($agent, 'fact2', 'grass is green', [0.0, 1.0, 0.0]));

            $recalled = $this->client->amem()->semRecall($agent, 1, [1.0, 0.0, 0.0]);
            self::assertSame('fact1', $recalled[0]['key']);
            self::assertSame('the sky is blue', $recalled[0]['content']);

            self::assertEqualsCanonicalizing(['fact1', 'fact2'], $this->client->amem()->semList($agent));
            self::assertSame(1, $this->client->amem()->semForget($agent, 'fact1'));
        });
    }

    public function testWorkingMemory(): void
    {
        $this->withLicense(function (): void {
            $session = $this->ns('work');
            self::assertSame('OK', $this->client->amem()->workSet($session, 'k1', 'v1'));
            self::assertSame('v1', $this->client->amem()->workGet($session, 'k1'));
            self::assertSame(['k1'], $this->client->amem()->workList($session));
            self::assertSame(1, $this->client->amem()->workDel($session, 'k1'));
            self::assertNull($this->client->amem()->workGet($session, 'k1'));
            // The session itself still exists (workDel only removed one key
            // from it), so clearing it returns 1; a session that never
            // existed returns 0.
            self::assertSame(1, $this->client->amem()->workClear($session));
            self::assertSame(0, $this->client->amem()->workClear($this->ns('never-existed')));
        });
    }

    public function testEpisodicMemory(): void
    {
        $this->withLicense(function (): void {
            $agent = $this->ns('epis');
            self::assertSame('OK', $this->client->amem()->episLog($agent, 'tool_call', 'called search()'));
            self::assertSame('OK', $this->client->amem()->episLog($agent, 'tool_result', 'got 3 results', metadata: '{"count":3}'));

            $events = $this->client->amem()->episQuery($agent);
            self::assertCount(2, $events);

            $filtered = $this->client->amem()->episQuery($agent, type: 'tool_call');
            self::assertCount(1, $filtered);
            self::assertSame('tool_call', $filtered[0]['type']);

            $stats = $this->client->amem()->episStats($agent);
            self::assertSame(2, $stats['total_events']);

            self::assertSame(1, $this->client->amem()->episClear($agent));
        });
    }

    public function testCrossMemorySearch(): void
    {
        $this->withLicense(function (): void {
            $agent = $this->ns('search');
            $this->client->amem()->semCreate($agent, 2);
            $this->client->amem()->semStore($agent, 'k1', 'semantic content', [1.0, 0.0]);

            $results = $this->client->amem()->search($agent, 5, [1.0, 0.0]);
            self::assertNotEmpty($results);
            self::assertSame('semantic', $results[0]['source']);
        });
    }

    public function testSummaryIsLicenseGated(): void
    {
        $this->withLicense(function (): void {
            $agent = $this->ns('summary');
            $version = $this->client->amem()->summarySet($agent, 'Initial summary.');
            self::assertSame(1, $version);
            $version2 = $this->client->amem()->summaryAppend($agent, ' More.');
            self::assertSame(2, $version2);

            $got = $this->client->amem()->summaryGet($agent);
            // The server joins an append to the stored text with a newline.
            self::assertSame("Initial summary.
 More.", $got[0]);

            self::assertContains($agent, $this->client->amem()->summaryList());
            self::assertSame(1, $this->client->amem()->summaryDrop($agent));
        });
    }
}
