<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;
use HotKV\Support\Numbers;

/**
 * Geospatial commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait GeoCommands
{
    /**
     * @param array<string, array{0: float, 1: float}> $members member => [longitude, latitude]
     * @return int members newly added (existing members are updated, not counted)
     */
    public function geoadd(string $key, array $members, bool $nx = false, bool $xx = false, bool $ch = false): mixed
    {
        $args = Args::new('GEOADD', $key)->flag('NX', $nx)->flag('XX', $xx)->flag('CH', $ch);
        foreach ($members as $member => [$lon, $lat]) {
            $args->push(Numbers::finite($lon), Numbers::finite($lat), (string) $member);
        }

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $members @return list<array{0: int|float|string, 1: int|float|string}|null> [longitude, latitude] per member (a RESP3 double under HELLO 3, a bulk string under RESP2) */
    public function geopos(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['GEOPOS', $key], $members));
    }

    /** @return string|null the distance, formatted by the server */
    public function geodist(string $key, string $member1, string $member2, string $unit = 'm'): mixed
    {
        return $this->dispatch(['GEODIST', $key, $member1, $member2, $unit]);
    }

    /** @param list<string> $members @return list<string|null> geohash strings */
    public function geohash(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['GEOHASH', $key], $members));
    }

    /**
     * One of $fromMember or ($fromLon, $fromLat) selects the search origin;
     * one of $byRadius or ($byBoxWidth, $byBoxHeight) selects the search area.
     *
     * @return list<mixed> members, or [member, ...extras] tuples when any WITH* flag is set
     */
    public function geosearch(
        string $key,
        ?string $fromMember = null,
        ?float $fromLon = null,
        ?float $fromLat = null,
        ?float $byRadius = null,
        ?float $byBoxWidth = null,
        ?float $byBoxHeight = null,
        string $unit = 'm',
        bool $asc = false,
        bool $desc = false,
        ?int $count = null,
        bool $any = false,
        bool $withCoord = false,
        bool $withDist = false,
        bool $withHash = false,
    ): mixed {
        $args = Args::new('GEOSEARCH', $key);
        $this->pushGeoOrigin($args, $fromMember, $fromLon, $fromLat);
        $this->pushGeoArea($args, $byRadius, $byBoxWidth, $byBoxHeight, $unit);
        $args->flag('ASC', $asc)->flag('DESC', $desc);
        if ($count !== null) {
            $args->push('COUNT', $count)->flag('ANY', $any);
        }
        $args->flag('WITHCOORD', $withCoord)->flag('WITHDIST', $withDist)->flag('WITHHASH', $withHash);

        return $this->dispatch($args->toArray());
    }

    /** @return int members stored in $destination */
    public function geosearchstore(
        string $destination,
        string $source,
        ?string $fromMember = null,
        ?float $fromLon = null,
        ?float $fromLat = null,
        ?float $byRadius = null,
        ?float $byBoxWidth = null,
        ?float $byBoxHeight = null,
        string $unit = 'm',
        bool $asc = false,
        bool $desc = false,
        ?int $count = null,
        bool $any = false,
        bool $storeDist = false,
    ): mixed {
        $args = Args::new('GEOSEARCHSTORE', $destination, $source);
        $this->pushGeoOrigin($args, $fromMember, $fromLon, $fromLat);
        $this->pushGeoArea($args, $byRadius, $byBoxWidth, $byBoxHeight, $unit);
        $args->flag('ASC', $asc)->flag('DESC', $desc);
        if ($count !== null) {
            $args->push('COUNT', $count)->flag('ANY', $any);
        }
        $args->flag('STOREDIST', $storeDist);

        return $this->dispatch($args->toArray());
    }

    /** Legacy radius search, kept for migration; prefer geosearch(). */
    public function georadius(
        string $key,
        float $longitude,
        float $latitude,
        float $radius,
        string $unit = 'm',
        bool $withCoord = false,
        bool $withDist = false,
        bool $withHash = false,
        ?int $count = null,
        bool $asc = false,
        bool $desc = false,
        ?string $store = null,
        ?string $storeDist = null,
    ): mixed {
        $args = Args::new('GEORADIUS', $key, Numbers::finite($longitude), Numbers::finite($latitude), Numbers::finite($radius), $unit)
            ->flag('WITHCOORD', $withCoord)->flag('WITHDIST', $withDist)->flag('WITHHASH', $withHash);
        if ($count !== null) {
            $args->push('COUNT', $count);
        }
        $args->flag('ASC', $asc)->flag('DESC', $desc)->opt('STORE', $store)->opt('STOREDIST', $storeDist);

        return $this->dispatch($args->toArray());
    }

    /** Legacy radius search from an existing member; prefer geosearch(). */
    public function georadiusbymember(
        string $key,
        string $member,
        float $radius,
        string $unit = 'm',
        bool $withCoord = false,
        bool $withDist = false,
        bool $withHash = false,
        ?int $count = null,
        bool $asc = false,
        bool $desc = false,
        ?string $store = null,
        ?string $storeDist = null,
    ): mixed {
        $args = Args::new('GEORADIUSBYMEMBER', $key, $member, Numbers::finite($radius), $unit)
            ->flag('WITHCOORD', $withCoord)->flag('WITHDIST', $withDist)->flag('WITHHASH', $withHash);
        if ($count !== null) {
            $args->push('COUNT', $count);
        }
        $args->flag('ASC', $asc)->flag('DESC', $desc)->opt('STORE', $store)->opt('STOREDIST', $storeDist);

        return $this->dispatch($args->toArray());
    }

    private function pushGeoOrigin(Args $args, ?string $fromMember, ?float $fromLon, ?float $fromLat): void
    {
        if ($fromMember !== null) {
            $args->push('FROMMEMBER', $fromMember);
        } elseif ($fromLon !== null && $fromLat !== null) {
            $args->push('FROMLONLAT', Numbers::finite($fromLon), Numbers::finite($fromLat));
        }
    }

    private function pushGeoArea(Args $args, ?float $byRadius, ?float $byBoxWidth, ?float $byBoxHeight, string $unit): void
    {
        if ($byRadius !== null) {
            $args->push('BYRADIUS', Numbers::finite($byRadius), $unit);
        } elseif ($byBoxWidth !== null && $byBoxHeight !== null) {
            $args->push('BYBOX', Numbers::finite($byBoxWidth), Numbers::finite($byBoxHeight), $unit);
        }
    }
}
