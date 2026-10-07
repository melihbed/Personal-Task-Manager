<?php

namespace App\Services\Canvas;

use RuntimeException;

/** Canvas no longer accepts the saved token (expired or revoked), so the user has to paste a new one. */
class CanvasReconnectRequired extends RuntimeException {}
