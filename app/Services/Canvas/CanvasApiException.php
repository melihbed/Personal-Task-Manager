<?php

namespace App\Services\Canvas;

use RuntimeException;

/** A Canvas API call failed. */
class CanvasApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
