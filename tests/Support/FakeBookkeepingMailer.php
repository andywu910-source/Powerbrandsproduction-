<?php

namespace Tests\Support;

use App\Services\Bookkeeping\BookkeepingMailer;
use App\Services\Bookkeeping\BookkeepingMessage;

class FakeBookkeepingMailer extends BookkeepingMailer
{
    /** @var array<int, BookkeepingMessage> */
    public array $sent = [];

    public bool $ready = true;

    public ?\Throwable $fail = null;

    public function configured(): bool
    {
        return $this->ready;
    }

    public function send(BookkeepingMessage $message): void
    {
        if ($this->fail) {
            throw $this->fail;
        }
        $this->sent[] = $message;
    }
}
