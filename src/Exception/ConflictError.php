<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Type conflict (409): idempotency conflict, resource already exists, invalid campaign state. */
class ConflictError extends CouryoException
{
}
