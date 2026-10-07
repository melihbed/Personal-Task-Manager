<?php

namespace App\Services\Assistant;

use RuntimeException;

/** The assistant could not get an answer from Ollama; the message says why, in words the user can act on. */
class AssistantUnavailable extends RuntimeException {}
