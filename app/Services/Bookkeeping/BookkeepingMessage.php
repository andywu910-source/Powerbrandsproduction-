<?php

namespace App\Services\Bookkeeping;

class BookkeepingMessage
{
    /**
     * @param  array<int, array{filename: string, mime: string, bytes: string}>  $attachments
     */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $to,
        public string $subject,
        public string $text,
        public string $html,
        public array $attachments,
    ) {}
}
