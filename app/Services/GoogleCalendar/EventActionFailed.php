<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/** Editing or deleting a Google event did not work. The message is shown to the user. */
class EventActionFailed extends RuntimeException {}
