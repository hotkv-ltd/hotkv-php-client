<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Protocol;

use HotKV\Exception\ProtocolException;
use HotKV\Protocol\BigNumber;
use HotKV\Protocol\PushMessage;
use HotKV\Protocol\Reader;
use HotKV\Protocol\ReplyError;
use HotKV\Tests\Support\ChunkedStream;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    /** @return Reader */
    private function reader(string $wire, int $chunkSize = 4096)
    {
        $stream = ChunkedStream::open($wire, $chunkSize);

        return new Reader($stream);
    }

    public function testSimpleString(): void
    {
        self::assertSame('OK', $this->reader("+OK\r\n")->readValue());
    }

    public function testError(): void
    {
        $value = $this->reader("-ERR something bad\r\n")->readValue();
        self::assertInstanceOf(ReplyError::class, $value);
        self::assertFalse($value->isBlob);
        self::assertSame('ERR something bad', $value->message);
        $ex = $value->toException();
        self::assertSame('ERR', $ex->getPrefix());
    }

    public function testInteger(): void
    {
        self::assertSame(1000, $this->reader(":1000\r\n")->readValue());
        self::assertSame(-42, $this->reader(":-42\r\n")->readValue());
        self::assertSame(0, $this->reader(":0\r\n")->readValue());
    }

    public function testBulkString(): void
    {
        self::assertSame('hello', $this->reader("$5\r\nhello\r\n")->readValue());
        self::assertSame('', $this->reader("$0\r\n\r\n")->readValue());
        self::assertNull($this->reader("$-1\r\n")->readValue());
    }

    public function testBulkStringIsBinarySafe(): void
    {
        // Embedded CR/LF/NUL bytes inside a length-prefixed bulk string
        // must not be mistaken for the terminator.
        $payload = "a\r\n\x00b";
        $wire = '$' . strlen($payload) . "\r\n" . $payload . "\r\n";
        self::assertSame($payload, $this->reader($wire)->readValue());
    }

    public function testArray(): void
    {
        self::assertSame(['foo', 'bar'], $this->reader("*2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n")->readValue());
        self::assertNull($this->reader("*-1\r\n")->readValue());
        self::assertSame([], $this->reader("*0\r\n")->readValue());
    }

    public function testNestedArray(): void
    {
        $wire = "*2\r\n*2\r\n:1\r\n:2\r\n*2\r\n:3\r\n:4\r\n";
        self::assertSame([[1, 2], [3, 4]], $this->reader($wire)->readValue());
    }

    public function testResp3Null(): void
    {
        self::assertNull($this->reader("_\r\n")->readValue());
    }

    public function testDouble(): void
    {
        self::assertSame(3.14, $this->reader(",3.14\r\n")->readValue());
        self::assertSame(INF, $this->reader(",inf\r\n")->readValue());
        self::assertSame(-INF, $this->reader(",-inf\r\n")->readValue());
        self::assertNan($this->reader(",nan\r\n")->readValue());
    }

    public function testBoolean(): void
    {
        self::assertTrue($this->reader("#t\r\n")->readValue());
        self::assertFalse($this->reader("#f\r\n")->readValue());
    }

    public function testBlobError(): void
    {
        $wire = "!21\r\nSYNTAX invalid syntax\r\n";
        $value = $this->reader($wire)->readValue();
        self::assertInstanceOf(ReplyError::class, $value);
        self::assertTrue($value->isBlob);
        self::assertSame('SYNTAX invalid syntax', $value->message);
        self::assertSame('SYNTAX', $value->toException()->getPrefix());
    }

    public function testVerbatimString(): void
    {
        $content = 'txt:Some string';
        $wire = '=' . strlen($content) . "\r\n{$content}\r\n";
        self::assertSame('Some string', $this->reader($wire)->readValue());
    }

    public function testBigNumber(): void
    {
        $digits = '3492890328409238509324850943850943825024385';
        $value = $this->reader("({$digits}\r\n")->readValue();
        self::assertInstanceOf(BigNumber::class, $value);
        self::assertSame($digits, (string) $value);
        self::assertSame($digits, $value->digits);
    }

    public function testMap(): void
    {
        $wire = "%2\r\n$3\r\nfoo\r\n:1\r\n$3\r\nbar\r\n:2\r\n";
        self::assertSame(['foo' => 1, 'bar' => 2], $this->reader($wire)->readValue());
    }

    public function testSet(): void
    {
        $wire = "~2\r\n$1\r\na\r\n$1\r\nb\r\n";
        self::assertSame(['a', 'b'], $this->reader($wire)->readValue());
    }

    public function testAttributeIsSkippedTransparently(): void
    {
        // One attribute pair ("hello" => "world") in front of the real
        // reply (the integer 5): the attribute is decoded and discarded,
        // and the value after it is returned.
        $wire = "|1\r\n$5\r\nhello\r\n$5\r\nworld\r\n:5\r\n";
        self::assertSame(5, $this->reader($wire)->readValue());
    }

    public function testPush(): void
    {
        $wire = ">2\r\n$7\r\nmessage\r\n$5\r\nhello\r\n";
        $value = $this->reader($wire)->readValue();
        self::assertInstanceOf(PushMessage::class, $value);
        self::assertSame(['message', 'hello'], $value->elements);
    }

    public function testInvalidLineTerminatorThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->reader("+OK\n")->readValue();
    }

    public function testInvalidIntegerThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->reader(":not-a-number\r\n")->readValue();
    }

    public function testUnknownTypeByteThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->reader("?\r\n")->readValue();
    }

    /** Every value type must decode correctly even when the stream only serves a byte or two per read(). */
    public function testDecodingSurvivesOneByteAtATimeReads(): void
    {
        $wire = "*3\r\n$5\r\nhello\r\n:42\r\n%1\r\n$3\r\nfoo\r\n,1.5\r\n";
        $value = $this->reader($wire, 1)->readValue();
        self::assertSame(['hello', 42, ['foo' => 1.5]], $value);
    }

    public function testMultipleValuesReadSequentiallyFromSameStream(): void
    {
        $reader = $this->reader("+OK\r\n:1\r\n$3\r\nfoo\r\n", 2);
        self::assertSame('OK', $reader->readValue());
        self::assertSame(1, $reader->readValue());
        self::assertSame('foo', $reader->readValue());
    }
}
