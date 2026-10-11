<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when connecting, writing or reading does not complete within the
 * configured connect/command timeout. A subtype of ConnectionException so
 * callers who only care about "the connection is unusable" can catch that
 * instead.
 */
class TimeoutException extends ConnectionException
{
}
