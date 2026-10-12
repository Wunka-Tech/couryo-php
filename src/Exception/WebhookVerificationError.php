<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Webhook signature, timestamp or body is not valid. Answer 400 and do not process the event. */
class WebhookVerificationError extends \UnexpectedValueException
{
}
