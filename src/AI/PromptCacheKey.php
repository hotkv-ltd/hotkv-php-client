<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\AI;

/**
 * Builds the canonical PromptCache key: a
 * deterministic, compact, sorted-key JSON encoding of the request,
 * SHA-256 hashed. Every official HotKV SDK implements exactly this
 * encoding, so the same logical request hashes to the same key
 * regardless of language.
 */
final class PromptCacheKey
{
    /**
     * @param list<array{role: string, content: string}> $messages in the caller's given order
     * @param array<string, string> $params generation parameters, already string-formatted by the caller
     *     (e.g. "0.7", not 0.7), so float formatting never differs between languages
     * @return string the lowercase hex SHA-256 digest (64 characters)
     */
    public static function build(string $provider, string $model, string $system, array $messages, array $params): string
    {
        return hash('sha256', self::canonicalJson($provider, $model, $system, $messages, $params));
    }

    /**
     * The canonical JSON string itself, exposed for testing determinism
     * and cross-SDK byte-for-byte comparison; build() is the one most
     * callers want.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, string> $params
     */
    public static function canonicalJson(string $provider, string $model, string $system, array $messages, array $params): string
    {
        $out = '{"provider":' . self::jsonString($provider)
            . ',"model":' . self::jsonString($model)
            . ',"system":' . self::jsonString($system)
            . ',"messages":[';

        foreach ($messages as $i => $message) {
            if ($i > 0) {
                $out .= ',';
            }
            $out .= '{"role":' . self::jsonString($message['role'])
                . ',"content":' . self::jsonString($message['content']) . '}';
        }

        $out .= '],"params":{';

        $keys = array_keys($params);
        usort($keys, 'strcmp'); // ascending by byte value, independent of any active locale

        foreach ($keys as $i => $key) {
            if ($i > 0) {
                $out .= ',';
            }
            $out .= self::jsonString($key) . ':' . self::jsonString($params[$key]);
        }

        return $out . '}}';
    }

    /**
     * Compact JSON string encoding: standard escaping for '"', '\' and
     * control characters below 0x20 (as \uXXXX); every other byte,
     * including non-ASCII UTF-8, is copied through unescaped.
     */
    private static function jsonString(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            switch ($c) {
                case '"':
                    $out .= '\\"';

                    break;
                case '\\':
                    $out .= '\\\\';

                    break;
                case "\n":
                    $out .= '\\n';

                    break;
                case "\r":
                    $out .= '\\r';

                    break;
                case "\t":
                    $out .= '\\t';

                    break;
                case "\x08":
                    $out .= '\\b';

                    break;
                case "\x0C":
                    $out .= '\\f';

                    break;
                default:
                    $ord = ord($c);
                    $out .= $ord < 0x20 ? sprintf('\\u%04x', $ord) : $c;
            }
        }

        return $out . '"';
    }
}
