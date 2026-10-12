<?php

declare(strict_types=1);

namespace Couryo\Http;

/** Network failure or timeout inside a Transport (the client retries it). */
class TransportException extends \RuntimeException
{
}
