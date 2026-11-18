<?php

namespace App\Exceptions;

use RuntimeException;

class RecordDeletionForbidden extends RuntimeException
{
    public function __construct(string $what = 'record')
    {
        parent::__construct("A {$what} can never be deleted. Void it with a reason instead.");
    }
}
