<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class ScriptingCommandsTest extends IntegrationTestCase
{
    public function testEvalReturnsValue(): void
    {
        $result = $this->client->eval("return 'hello'", [], []);
        self::assertSame('hello', $result);
    }

    public function testEvalWithKeysAndArgs(): void
    {
        $k = $this->key();
        $result = $this->client->eval('return redis.call("SET", KEYS[1], ARGV[1])', [$k], ['value1']);
        self::assertSame('OK', $result);
        self::assertSame('value1', $this->client->get($k));
    }

    public function testScriptLoadExistsAndEvalsha(): void
    {
        $sha = $this->client->scriptLoad("return 'loaded'");
        self::assertIsString($sha);
        self::assertSame([1], $this->client->scriptExists([$sha]));
        self::assertSame('loaded', $this->client->evalsha($sha, [], []));
    }

    public function testScriptExistsFalseForUnknownSha(): void
    {
        self::assertSame([0], $this->client->scriptExists([str_repeat('0', 40)]));
    }

    public function testEvalOrLoadFallsBackOnNoscript(): void
    {
        $result = $this->client->evalOrLoad("return 'via-eval-or-load'", [], []);
        self::assertSame('via-eval-or-load', $result);

        // Second call: the script is now cached server side, exercised via EVALSHA directly.
        $result2 = $this->client->evalOrLoad("return 'via-eval-or-load'", [], []);
        self::assertSame('via-eval-or-load', $result2);
    }

    public function testScriptFlush(): void
    {
        self::assertSame('OK', $this->client->scriptFlush());
    }
}
