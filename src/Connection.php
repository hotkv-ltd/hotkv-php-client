<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Exception\ConnectionException;
use HotKV\Exception\ProtocolException;
use HotKV\Protocol\PushMessage;
use HotKV\Protocol\Reader;
use HotKV\Protocol\ReplyError;
use HotKV\Protocol\Writer;

/**
 * A single TCP, TLS or Unix socket connection to a HotKV server: dials,
 * negotiates HELLO/RESP3 (falling back to RESP2), authenticates, selects
 * the database and exposes both a simple request/response call and the
 * write/flush/read primitives Pipeline and Transaction build on.
 *
 * Not safe for concurrent use from more than one caller at a time; Client
 * and ConnectionPool are responsible for that.
 */
final class Connection
{
    /** @var resource|null */
    private $stream = null;
    private ?Reader $reader = null;
    private ?Writer $writer = null;
    private int $protocol = 2;
    private float $lastUsedAt = 0.0;
    /** @var (callable(PushMessage): void)|null */
    private $pushHandler = null;

    public function __construct(
        private readonly ClientOptions $options,
    ) {
    }

    public function options(): ClientOptions
    {
        return $this->options;
    }

    public function isConnected(): bool
    {
        return $this->stream !== null;
    }

    /** The RESP protocol version actually negotiated with the server (2 or 3). */
    public function protocol(): int
    {
        return $this->protocol;
    }

    public function lastUsedAt(): float
    {
        return $this->lastUsedAt;
    }

    public function connect(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $address = $this->buildAddress();
        $flags = STREAM_CLIENT_CONNECT;
        if ($this->options->persistent) {
            $flags |= STREAM_CLIENT_PERSISTENT;
        }

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->options->connectTimeout > 0 ? $this->options->connectTimeout : null,
            $flags,
            $this->buildContext(),
        );

        if ($stream === false) {
            throw new ConnectionException(sprintf(
                'Failed to connect to HotKV server at %s: %s (errno %d)',
                $address,
                $errstr,
                $errno,
            ));
        }

        stream_set_blocking($stream, true);
        $this->applyTimeout($stream);

        $this->stream = $stream;
        $this->reader = new Reader($stream);
        $this->writer = new Writer($stream);
        $this->lastUsedAt = microtime(true);

        try {
            $this->negotiateProtocol();
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->reader = null;
        $this->writer = null;
    }

    /** Sends one command and returns its decoded reply, throwing on a server error reply. */
    public function sendCommand(array $args): mixed
    {
        $this->connect();
        $this->writer->writeCommand($args);
        $this->flush();
        $reply = $this->readReply();
        if ($reply instanceof ReplyError) {
            throw $reply->toException();
        }

        return $reply;
    }

    /**
     * Installs (or clears) the callback that receives RESP3 out of band
     * push messages (client side caching "invalidate" frames and the like)
     * that arrive between command replies. Without one they are dropped.
     *
     * @param (callable(PushMessage): void)|null $handler
     */
    public function setPushHandler(?callable $handler): void
    {
        $this->pushHandler = $handler;
    }

    /**
     * Reads the reply to the command just sent. A server may interleave
     * push frames ahead of it (for example after CLIENT TRACKING ON); those
     * are not the reply, so they go to the push handler and the read
     * continues. Does not convert an error reply to an exception.
     */
    public function readReply(): mixed
    {
        while (true) {
            try {
                $value = $this->reader->readValue();
            } catch (ConnectionException | ProtocolException $e) {
                // The server closed the connection, a read timed out, or the
                // stream is out of sync: a late reply would be taken for the
                // next command's, so never reuse this socket.
                $this->close();

                throw $e;
            }
            if (!$value instanceof PushMessage) {
                return $value;
            }
            if ($this->pushHandler !== null) {
                ($this->pushHandler)($value);
            }
        }
    }

    /** Buffers one command without sending it yet (for pipelining). */
    public function queue(array $args): void
    {
        $this->connect();
        $this->writer->writeCommand($args);
    }

    /** Sends every buffered command in one write. */
    public function flush(): void
    {
        try {
            $this->writer->flush();
        } catch (ConnectionException $e) {
            // A failed write leaves the stream in an unknown state: drop it
            // so the next command opens a fresh connection.
            $this->close();

            throw $e;
        }
        $this->lastUsedAt = microtime(true);
    }

    /** Reads one reply without converting a ReplyError to an exception. */
    public function readRaw(): mixed
    {
        return $this->reader->readValue();
    }

    /** True when PING got a PONG back; used by the pool's borrow health check. Never throws. */
    public function ping(): bool
    {
        try {
            $reply = $this->sendCommand(['PING']);

            return $reply === 'PONG' || $reply === [0 => 'PONG'];
        } catch (\Throwable) {
            $this->close();

            return false;
        }
    }

    private function negotiateProtocol(): void
    {
        $args = ['HELLO', (string) $this->options->protocol];
        if ($this->options->password !== null) {
            $args[] = 'AUTH';
            $args[] = $this->options->username ?? 'default';
            $args[] = $this->options->password;
        }
        if ($this->options->clientName !== null) {
            $args[] = 'SETNAME';
            $args[] = $this->options->clientName;
        }

        $reply = $this->sendRaw($args);

        if ($reply instanceof ReplyError) {
            // Old server without HELLO support, or the requested protocol
            // was rejected: fall back to a RESP2 handshake done by hand.
            $this->protocol = 2;
            $this->authenticateResp2();
            $this->selectDatabase();
            $this->setClientName();

            return;
        }

        $this->protocol = $this->options->protocol;
        $this->selectDatabase();
    }

    private function authenticateResp2(): void
    {
        if ($this->options->password === null) {
            return;
        }
        $args = $this->options->username !== null
            ? ['AUTH', $this->options->username, $this->options->password]
            : ['AUTH', $this->options->password];
        $this->expectOk($this->sendRaw($args));
    }

    private function selectDatabase(): void
    {
        if ($this->options->database === 0) {
            return;
        }
        $this->expectOk($this->sendRaw(['SELECT', (string) $this->options->database]));
    }

    private function setClientName(): void
    {
        if ($this->options->clientName === null) {
            return;
        }
        $this->expectOk($this->sendRaw(['CLIENT', 'SETNAME', $this->options->clientName]));
    }

    private function expectOk(mixed $reply): void
    {
        if ($reply instanceof ReplyError) {
            throw $reply->toException();
        }
    }

    private function sendRaw(array $args): mixed
    {
        $this->writer->writeCommand($args);
        $this->writer->flush();

        return $this->reader->readValue();
    }

    private function buildAddress(): string
    {
        if ($this->options->path !== null) {
            return 'unix://' . $this->options->path;
        }

        $scheme = $this->options->tls ? 'tls' : 'tcp';

        return "{$scheme}://{$this->options->host}:{$this->options->port}";
    }

    /** @return resource */
    private function buildContext()
    {
        $opts = [];
        if ($this->options->tls) {
            $ssl = [
                'verify_peer' => !$this->options->tlsInsecureSkipVerify,
                'verify_peer_name' => !$this->options->tlsInsecureSkipVerify,
                'allow_self_signed' => $this->options->tlsInsecureSkipVerify,
                'peer_name' => $this->options->tlsServerName ?? $this->options->host,
            ];
            if ($this->options->tlsCaFile !== null) {
                $ssl['cafile'] = $this->options->tlsCaFile;
            }
            if ($this->options->tlsCertFile !== null) {
                $ssl['local_cert'] = $this->options->tlsCertFile;
            }
            if ($this->options->tlsKeyFile !== null) {
                $ssl['local_pk'] = $this->options->tlsKeyFile;
            }
            $opts['ssl'] = $ssl;
        }

        return stream_context_create($opts);
    }

    /** @param resource $stream */
    private function applyTimeout($stream): void
    {
        $timeout = $this->options->commandTimeout;
        if ($timeout > 0) {
            $seconds = (int) floor($timeout);
            $micros = (int) round(($timeout - $seconds) * 1_000_000);
            stream_set_timeout($stream, $seconds, $micros);
        }
    }
}
