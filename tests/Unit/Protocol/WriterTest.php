<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Protocol;

use HotKV\Protocol\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    private function readBack($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }

    public function testWritesBulkStringArray(): void
    {
        $stream = fopen('php://memory', 'r+b');
        $writer = new Writer($stream);
        $writer->writeCommand(['SET', 'key', 'value']);
        $writer->flush();

        self::assertSame("*3\r\n\$3\r\nSET\r\n\$3\r\nkey\r\n\$5\r\nvalue\r\n", $this->readBack($stream));
    }

    public function testEncodesIntFloatAndBoolArguments(): void
    {
        $stream = fopen('php://memory', 'r+b');
        $writer = new Writer($stream);
        $writer->writeCommand(['INCRBY', 'k', 5, 2.5, true, false]);
        $writer->flush();

        $wire = $this->readBack($stream);
        self::assertStringContainsString("\$1\r\n5\r\n", $wire);
        self::assertStringContainsString("\$3\r\n2.5\r\n", $wire);
        self::assertStringContainsString("\$1\r\n1\r\n", $wire);
        self::assertStringContainsString("\$1\r\n0\r\n", $wire);
    }

    public function testBinarySafeArgument(): void
    {
        $stream = fopen('php://memory', 'r+b');
        $writer = new Writer($stream);
        $payload = "a\r\n\x00b";
        $writer->writeCommand(['SET', 'k', $payload]);
        $writer->flush();

        self::assertSame("*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$" . strlen($payload) . "\r\n{$payload}\r\n", $this->readBack($stream));
    }

    public function testMultipleQueuedCommandsFlushInOneBuffer(): void
    {
        $stream = fopen('php://memory', 'r+b');
        $writer = new Writer($stream);
        self::assertFalse($writer->hasPending());
        $writer->writeCommand(['GET', 'a']);
        $writer->writeCommand(['GET', 'b']);
        self::assertTrue($writer->hasPending());
        $writer->flush();
        self::assertFalse($writer->hasPending());

        self::assertSame(
            "*2\r\n\$3\r\nGET\r\n\$1\r\na\r\n*2\r\n\$3\r\nGET\r\n\$1\r\nb\r\n",
            $this->readBack($stream),
        );
    }

    public function testFormatFloatWholeNumberKeepsDecimalPoint(): void
    {
        self::assertSame('1.0', Writer::formatFloat(1.0));
        self::assertSame('-2.0', Writer::formatFloat(-2.0));
    }

    public function testFormatFloatShortestRoundTrip(): void
    {
        self::assertSame('0.7', Writer::formatFloat(0.7));
        self::assertSame('0.85', Writer::formatFloat(0.85));
        self::assertSame('3.14159', Writer::formatFloat(3.14159));
    }

    public function testFormatFloatSpecialValues(): void
    {
        self::assertSame('inf', Writer::formatFloat(INF));
        self::assertSame('-inf', Writer::formatFloat(-INF));
    }

    public function testFormatFloatRejectsNan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Writer::formatFloat(NAN);
    }

    public function testFloatArgumentsNeverUseExponentForm(): void
    {
        $writer = new Writer(fopen('php://memory', 'r+b'));
        self::assertSame('0.00001', $writer->argToString(1.0E-5));
        self::assertSame('100000000000000000000000.0', $writer->argToString(1.0E+23));
    }

    public function testArgToStringNeverEmitsLeadingPlus(): void
    {
        $writer = new Writer(fopen('php://memory', 'r+b'));
        self::assertSame('5', $writer->argToString(5));
        self::assertStringNotContainsString('+', $writer->argToString(5));
    }
}
