<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Type api_error (500 or 503): error on the Couryo side. Safe to repeat with the same Idempotency-Key. */
class ApiError extends CouryoException
{
}
