<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Type authentication (401): missing, invalid or revoked API key. */
class AuthenticationError extends CouryoException
{
}
