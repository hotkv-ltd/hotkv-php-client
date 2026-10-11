<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\AI;

use HotKV\AI\PromptCacheKey;
use PHPUnit\Framework\TestCase;

final class PromptCacheKeyTest extends TestCase
{
    public function testCanonicalJsonMatchesSpecFieldOrderAndParamSorting(): void
    {
        $json = PromptCacheKey::canonicalJson(
            'openai',
            'gpt-6-sol',
            'You are helpful.',
            [['role' => 'user', 'content' => 'Hi']],
            ['temperature' => '0.7', 'max_tokens' => '100'],
        );

        self::assertSame(
            '{"provider":"openai","model":"gpt-6-sol","system":"You are helpful.",'
            . '"messages":[{"role":"user","content":"Hi"}],"params":{"max_tokens":"100","temperature":"0.7"}}',
            $json,
        );
    }

    public function testKeyIsLowercaseHex64CharSha256OfTheCanonicalJson(): void
    {
        $json = PromptCacheKey::canonicalJson('openai', 'gpt-6-sol', 'You are helpful.', [['role' => 'user', 'content' => 'Hi']], ['temperature' => '0.7', 'max_tokens' => '100']);
        $key = PromptCacheKey::build('openai', 'gpt-6-sol', 'You are helpful.', [['role' => 'user', 'content' => 'Hi']], ['temperature' => '0.7', 'max_tokens' => '100']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        self::assertSame(hash('sha256', $json), $key);
        // Independently computed reference digest; guards
        // against a canonical-form regression that hash('sha256', ...) of
        // the same (possibly also-broken) json would not catch.
        self::assertSame('faa10cd0f6f65e1d4fb9dc3e779eac8d98b9d0b73fbfe36ff8bf07d9ba1e89d2', $key);
    }

    public function testEmptySystemAndParamsAreEncodedNotOmitted(): void
    {
        $json = PromptCacheKey::canonicalJson('openai', 'gpt-6-sol', '', [], []);
        self::assertSame('{"provider":"openai","model":"gpt-6-sol","system":"","messages":[],"params":{}}', $json);
        self::assertSame('c8722ee88e58b8bb014d4474518889e893bce61cf93258f34810066131200a46', PromptCacheKey::build('openai', 'gpt-6-sol', '', [], []));
    }

    public function testNonAsciiIsLiteralUtf8AndControlCharsAreEscaped(): void
    {
        $system = "café 你好 \"quoted\" line1\nline2";
        $json = PromptCacheKey::canonicalJson('p', 'm', $system, [], []);

        self::assertSame(
            '{"provider":"p","model":"m","system":"café 你好 \"quoted\" line1\nline2","messages":[],"params":{}}',
            $json,
        );
        self::assertSame('0293b1e960b03d1d63bb620de07a0914a443372246018a81357bbe4af476aff4', PromptCacheKey::build('p', 'm', $system, [], []));
    }

    public function testCompactEncodingHasNoInsignificantWhitespace(): void
    {
        $json = PromptCacheKey::canonicalJson('p', 'm', 's', [['role' => 'user', 'content' => 'hi']], ['a' => '1']);
        self::assertStringNotContainsString(': ', $json);
        self::assertStringNotContainsString(', ', $json);
        self::assertStringNotContainsString("\n", $json);
    }

    public function testMessagesKeepCallerOrderRegardlessOfParamSorting(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
            ['role' => 'assistant', 'content' => 'c'],
        ];
        $json = PromptCacheKey::canonicalJson('p', 'm', '', $messages, []);
        self::assertSame(
            '{"provider":"p","model":"m","system":"","messages":'
            . '[{"role":"system","content":"a"},{"role":"user","content":"b"},{"role":"assistant","content":"c"}],"params":{}}',
            $json,
        );
    }

    public function testKeyIsDeterministicAcrossRepeatedCalls(): void
    {
        $build = fn () => PromptCacheKey::build('p', 'm', 's', [['role' => 'user', 'content' => 'hi']], ['b' => '2', 'a' => '1']);
        self::assertSame($build(), $build());
    }

    public function testDifferentInputsProduceDifferentKeys(): void
    {
        $a = PromptCacheKey::build('openai', 'gpt-6-sol', '', [], ['temperature' => '0.7']);
        $b = PromptCacheKey::build('openai', 'gpt-6-sol', '', [], ['temperature' => '0.8']);
        self::assertNotSame($a, $b);
    }

    public function testParamKeysAreSortedByPlainByteValueNotLocale(): void
    {
        // Uppercase byte values sort before lowercase in a plain byte-value
        // (strcmp) sort; a locale-aware sort could reorder these.
        $json = PromptCacheKey::canonicalJson('p', 'm', '', [], ['b' => '1', 'A' => '2', 'a' => '3']);
        self::assertSame('{"provider":"p","model":"m","system":"","messages":[],"params":{"A":"2","a":"3","b":"1"}}', $json);
    }
}
