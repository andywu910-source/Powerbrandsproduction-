<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A compliance rule blocked the action (step locked, signer not allowed, out of
 * tolerance, not enough stock ...). Rendered as a friendly message; HTTP 409 for
 * locked steps, 422 for rule failures.
 */
class GateFailed extends RuntimeException
{
    /** @param string[] $reasons */
    public function __construct(public array $reasons, public int $status = 422)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function because(string ...$reasons): self
    {
        return new self($reasons);
    }

    public static function locked(string $reason): self
    {
        return new self([$reason], 409);
    }
}
