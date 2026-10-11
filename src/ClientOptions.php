<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Exception\HotKVException;

/**
 * Connection options for a Client. Build one with the constructor,
 * fromArray() or fromUrl(), then pass it to `new Client($options)`
 * (Client also accepts a plain "hotkv://" URL string or option array
 * directly and builds this internally).
 */
final class ClientOptions
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly ?string $path = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly int $database = 0,
        public readonly ?string $clientName = null,
        public readonly float $connectTimeout = 5.0,
        public readonly float $commandTimeout = 5.0,
        public readonly int $protocol = 3,
        public readonly bool $persistent = false,
        public readonly bool $retryReadsOnce = true,
        public readonly bool $tls = false,
        public readonly ?string $tlsCaFile = null,
        public readonly ?string $tlsCertFile = null,
        public readonly ?string $tlsKeyFile = null,
        public readonly ?string $tlsServerName = null,
        public readonly bool $tlsInsecureSkipVerify = false,
        public readonly int $poolSize = 1,
        public readonly float $poolIdleTimeout = 60.0,
    ) {
    }

    /**
     * Builds options from an associative array. Recognized keys match the
     * constructor's named parameters in either camelCase ("connectTimeout")
     * or snake_case ("connect_timeout").
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        if (isset($options['url'])) {
            $base = self::fromUrl((string) $options['url']);
            unset($options['url']);
        } else {
            $base = new self();
        }

        $get = static function (array $o, string $camel, string $snake, mixed $default) {
            if (array_key_exists($camel, $o)) {
                return $o[$camel];
            }
            if (array_key_exists($snake, $o)) {
                return $o[$snake];
            }

            return $default;
        };

        return new self(
            host: (string) $get($options, 'host', 'host', $base->host),
            port: (int) $get($options, 'port', 'port', $base->port),
            path: $get($options, 'path', 'path', $base->path),
            username: $get($options, 'username', 'username', $base->username),
            password: $get($options, 'password', 'password', $base->password),
            database: (int) $get($options, 'database', 'database', $base->database),
            clientName: $get($options, 'clientName', 'client_name', $base->clientName),
            connectTimeout: (float) $get($options, 'connectTimeout', 'connect_timeout', $base->connectTimeout),
            commandTimeout: (float) $get($options, 'commandTimeout', 'command_timeout', $base->commandTimeout),
            protocol: (int) $get($options, 'protocol', 'protocol', $base->protocol),
            persistent: (bool) $get($options, 'persistent', 'persistent', $base->persistent),
            retryReadsOnce: (bool) $get($options, 'retryReadsOnce', 'retry_reads_once', $base->retryReadsOnce),
            tls: (bool) $get($options, 'tls', 'tls', $base->tls),
            tlsCaFile: $get($options, 'tlsCaFile', 'tls_ca_file', $base->tlsCaFile),
            tlsCertFile: $get($options, 'tlsCertFile', 'tls_cert_file', $base->tlsCertFile),
            tlsKeyFile: $get($options, 'tlsKeyFile', 'tls_key_file', $base->tlsKeyFile),
            tlsServerName: $get($options, 'tlsServerName', 'tls_server_name', $base->tlsServerName),
            tlsInsecureSkipVerify: (bool) $get($options, 'tlsInsecureSkipVerify', 'tls_insecure_skip_verify', $base->tlsInsecureSkipVerify),
            poolSize: (int) $get($options, 'poolSize', 'pool_size', $base->poolSize),
            poolIdleTimeout: (float) $get($options, 'poolIdleTimeout', 'pool_idle_timeout', $base->poolIdleTimeout),
        );
    }

    /**
     * Parses a connection URL of the form
     * "(hotkv|hotkvs|redis|rediss|unix)://[user[:pass]@]host[:port][/db]".
     * hotkvs:// and rediss:// enable TLS; redis:// and rediss:// are
     * accepted for drop-in migration and behave exactly like hotkv:// and
     * hotkvs://. unix:///path/to/socket[?db=N] addresses a Unix socket.
     *
     * @param array<string, mixed> $overrides applied on top of the parsed URL, same keys as fromArray()
     */
    public static function fromUrl(string $url, array $overrides = []): self
    {
        $parts = self::splitUrl($url);
        $scheme = $parts['scheme'];

        $tls = false;
        switch ($scheme) {
            case 'hotkv':
            case 'redis':
                break;
            case 'hotkvs':
            case 'rediss':
                $tls = true;

                break;
            case 'unix':
                if ($parts['path'] === '') {
                    throw new HotKVException("Invalid HotKV connection URL: unix socket path is missing in \"" . self::redactUrl($url) . "\"");
                }
                $database = 0;
                if ($parts['query'] !== '') {
                    parse_str($parts['query'], $query);
                    if (isset($query['db'])) {
                        $database = (int) $query['db'];
                    }
                }

                return self::fromArray(array_merge([
                    'path' => $parts['path'],
                    'username' => $parts['user'] !== null ? rawurldecode($parts['user']) : null,
                    'password' => $parts['pass'] !== null ? rawurldecode($parts['pass']) : null,
                    'database' => $database,
                ], $overrides));
            default:
                throw new HotKVException("Unsupported HotKV connection URL scheme \"{$scheme}\" (want hotkv, hotkvs, redis, rediss or unix).");
        }

        $host = $parts['host'] !== '' ? $parts['host'] : '127.0.0.1';
        $port = $parts['port'] ?? 6379;

        $username = $parts['user'] !== null && $parts['user'] !== '' ? rawurldecode($parts['user']) : null;
        $password = $parts['pass'] !== null ? rawurldecode($parts['pass']) : null;
        if ($password === null && $username !== null) {
            // A single userinfo component with no colon is the legacy
            // single password AUTH form, e.g. redis://:secret@host is the
            // canonical spelling, but redis://secret@host is common too.
            $password = $username;
            $username = null;
        }

        $database = 0;
        $db = trim($parts['path'], '/');
        if ($db !== '') {
            if (preg_match('/^\d+$/', $db) !== 1) {
                throw new HotKVException("Invalid database index in HotKV connection URL path: \"{$db}\"");
            }
            $database = (int) $db;
        }

        $options = [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'database' => $database,
            'tls' => $tls,
        ];

        if ($parts['query'] !== '') {
            parse_str($parts['query'], $query);
            foreach (['client_name', 'connect_timeout', 'command_timeout'] as $key) {
                if (isset($query[$key])) {
                    $options[$key] = $query[$key];
                }
            }
        }

        return self::fromArray(array_merge($options, $overrides));
    }

    /** The URL with any user name and password replaced by "***", for error messages. */
    public static function redactUrl(string $url): string
    {
        return preg_replace('#^([a-zA-Z][a-zA-Z0-9+.-]*://)[^/?\#]*@#', '$1***@', $url) ?? $url;
    }

    /**
     * Splits a connection URL into its parts by hand, instead of using
     * PHP's parse_url(), which returns false for a URL with an empty
     * authority such as "hotkv://" or "unix:///path" for any scheme it
     * does not special-case (it only special-cases "file").
     *
     * @return array{scheme: string, user: string|null, pass: string|null, host: string, port: int|null, path: string, query: string}
     */
    private static function splitUrl(string $url): array
    {
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*)://([^/?\#]*)([^?\#]*)(?:\?([^\#]*))?#', $url, $m) !== 1) {
            throw new HotKVException("Invalid HotKV connection URL: \"" . self::redactUrl($url) . "\"");
        }

        $authority = $m[2];
        $user = null;
        $pass = null;
        $host = $authority;
        $port = null;

        $at = strrpos($authority, '@');
        if ($at !== false) {
            $userinfo = substr($authority, 0, $at);
            $host = substr($authority, $at + 1);
            $colon = strpos($userinfo, ':');
            if ($colon !== false) {
                $user = substr($userinfo, 0, $colon);
                $pass = substr($userinfo, $colon + 1);
            } else {
                $user = $userinfo;
            }
        }

        $portColon = strrpos($host, ':');
        if ($portColon !== false) {
            $port = (int) substr($host, $portColon + 1);
            $host = substr($host, 0, $portColon);
        }

        return [
            'scheme' => strtolower($m[1]),
            'user' => $user,
            'pass' => $pass,
            'host' => $host,
            'port' => $port,
            'path' => $m[3] ?? '',
            'query' => $m[4] ?? '',
        ];
    }
}
