<?php

namespace App\Services\GoogleCalendar;

/**
 * How Google Calendar is shown in the app. Google asks that its product icon is only ever used next to the
 * product's name, never implies endorsement, and comes with a trademark line.
 *
 * The icon is the file Google serves for its own pages (gstatic.com/images/branding/product/2x/calendar_2020q4_48dp.png).
 */
class GoogleCalendarBranding
{
    public const ICON = 'images/integrations/google-calendar.png';

    public const LEGAL = 'Google Calendar is a trademark of Google LLC.';

    public static function icon(): string
    {
        return asset(self::ICON);
    }
}
