<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Cluster;

/**
 * CRC16/XMODEM (polynomial 0x1021, initial value 0, no reflection): the
 * checksum HotKV cluster mode hashes keys with.
 * checksum("123456789") === 0x31C3.
 */
final class Crc16
{
    private const SLOT_COUNT = 16384;

    public static function checksum(string $data): int
    {
        $crc = 0;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }

    /**
     * The cluster hash slot (0..16383) for a key, honoring "{hash tag}"
     * substrings exactly like CLUSTER KEYSLOT: if the key contains '{'
     * and a '}' follows it with at least one byte in between, only the
     * bytes between them are hashed; otherwise the whole key is hashed.
     */
    public static function keySlot(string $key): int
    {
        $hashed = $key;
        $open = strpos($key, '{');
        if ($open !== false) {
            $close = strpos($key, '}', $open + 1);
            if ($close !== false && $close > $open + 1) {
                $hashed = substr($key, $open + 1, $close - $open - 1);
            }
        }

        return self::checksum($hashed) % self::SLOT_COUNT;
    }
}
