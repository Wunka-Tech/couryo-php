<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Type rate_limited (429): too many calls per second. The client already retried it. */
class RateLimitError extends CouryoException
{
}
