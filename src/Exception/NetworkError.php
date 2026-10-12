<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Network failure or timeout (status 0, code network_error). The client already retried it. */
class NetworkError extends CouryoException
{
}
