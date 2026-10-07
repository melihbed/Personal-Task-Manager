<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/** A repeating Google event whose rule a routine cannot express. The message is shown to the user. */
class UnsupportedRecurrence extends RuntimeException {}
