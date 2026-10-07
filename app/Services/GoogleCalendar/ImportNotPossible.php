<?php

namespace App\Services\GoogleCalendar;

use RuntimeException;

/** A Google event cannot become the chosen kind of planner item. The message is shown to the user. */
class ImportNotPossible extends RuntimeException {}
