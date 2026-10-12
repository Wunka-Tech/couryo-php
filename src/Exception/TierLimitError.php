<?php

declare(strict_types=1);

namespace Couryo\Exception;

/** Type tier_limit (429, or 402 for AI credits): daily or monthly limit, spend limit, sending paused. */
class TierLimitError extends CouryoException
{
}
