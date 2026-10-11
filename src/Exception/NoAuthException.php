<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when a command is sent before successful authentication
 * ("NOAUTH" error prefix).
 */
class NoAuthException extends ServerException
{
}
