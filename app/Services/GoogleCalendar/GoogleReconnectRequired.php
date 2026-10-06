<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/** Google no longer accepts the saved authorization, so the user has to connect again. Retrying cannot help. */
class GoogleReconnectRequired extends RuntimeException {}
