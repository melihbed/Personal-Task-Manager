<?php

namespace App\Services\Pomodoro;

use RuntimeException;

/** A timer action that cannot be done right now; the message says why, for the user. */
class PomodoroException extends RuntimeException {}
