<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when the server rejects an enterprise/AI command with a
 * "NOLICENSE" error because the currently loaded license does not cover
 * that feature. Integration tests can catch this and skip, since it
 * reflects the server's license rather than a defect in the call.
 */
class NotLicensedException extends ServerException
{
}
