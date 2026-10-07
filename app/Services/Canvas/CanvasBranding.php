<?php

namespace App\Services\Canvas;

/** How Canvas is shown in the app: a simple icon drawn for this app, and a trademark line. */
class CanvasBranding
{
    public const ICON = 'images/integrations/canvas.svg';

    public const LEGAL = 'Canvas is a trademark of Instructure, Inc.';

    public static function icon(): string
    {
        return asset(self::ICON);
    }
}
