<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/**
 * A Google Calendar API call failed. 429 and 5xx are worth retrying; 404 and 410 on a delete mean
 * the event is already gone.
 */
class GoogleApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function isGone(): bool
    {
        return in_array($this->status, [404, 410], true);
    }
}
