<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when a TCP/Unix/TLS connection to the server cannot be
 * established, or drops while a command is in flight.
 */
class ConnectionException extends HotKVException
{
}
