<?php

namespace App\Services\GoogleCalendar;

use Carbon\CarbonImmutable;

/**
 * Reads the recurrence rule of a Google event into what a routine can hold: weekdays and an optional last day.
 * Routines repeat weekly (or daily) on chosen weekdays and end on a date, so anything else is refused with a
 * reason rather than imported wrongly.
 */
class RecurrenceParser
{
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    private const ALLOWED = ['FREQ', 'INTERVAL', 'BYDAY', 'UNTIL', 'WKST'];

    /**
     * @param  list<string>  $recurrence  the event's recurrence lines
     * @param  CarbonImmutable  $start  the first occurrence, in the routine's timezone (used when no BYDAY is given)
     * @return array{days: list<int>, until: string|null}
     *
     * @throws UnsupportedRecurrence
     */
    public static function parse(array $recurrence, CarbonImmutable $start): array
    {
        $rules = array_values(array_filter($recurrence, fn (string $line) => stripos($line, 'RRULE:') === 0));

        if (count($rules) !== 1) {
            throw new UnsupportedRecurrence('This event does not have a single repeat rule, so it cannot become a routine.');
        }

        $parts = [];

        foreach (explode(';', substr($rules[0], 6)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[strtoupper($key)] = strtoupper($value);
        }

        if (isset($parts['COUNT'])) {
            throw new UnsupportedRecurrence('This event repeats a fixed number of times. Routines repeat until an end date, so it cannot become a routine.');
        }

        $frequency = $parts['FREQ'] ?? '';

        if (! in_array($frequency, ['WEEKLY', 'DAILY'], true)) {
            throw new UnsupportedRecurrence('Routines repeat weekly or daily. This event repeats '.strtolower($frequency ?: 'in another way').'.');
        }

        if (array_diff(array_keys($parts), self::ALLOWED) !== []) {
            throw new UnsupportedRecurrence('This event repeats in a way routines do not support yet.');
        }

        if (($parts['INTERVAL'] ?? '1') !== '1') {
            throw new UnsupportedRecurrence('This event repeats every '.$parts['INTERVAL'].' '.($frequency === 'WEEKLY' ? 'weeks' : 'days').'. Routines repeat every week or every day.');
        }

        return ['days' => self::days($parts['BYDAY'] ?? null, $frequency, $start), 'until' => self::until($parts['UNTIL'] ?? null, $start->timezoneName)];
    }

    /**
     * @return list<int>
     */
    private static function days(?string $byDay, string $frequency, CarbonImmutable $start): array
    {
        if ($byDay === null || $byDay === '') {
            return $frequency === 'DAILY' ? [1, 2, 3, 4, 5, 6, 7] : [$start->isoWeekday()];
        }

        $days = [];

        foreach (explode(',', strtoupper($byDay)) as $code) {
            if (! isset(self::DAYS[$code])) {
                throw new UnsupportedRecurrence('This event repeats on a day pattern routines do not support, such as "the second Tuesday".');
            }

            $days[] = self::DAYS[$code];
        }

        sort($days);

        return array_values(array_unique($days));
    }

    /** The last day, as a date in the routine's timezone. UNTIL is either a date or a UTC date and time. */
    private static function until(?string $until, string $timezone): ?string
    {
        if ($until === null || $until === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $until) === 1) {
            return CarbonImmutable::createFromFormat('!Ymd', $until, $timezone)->toDateString();
        }

        if (preg_match('/^(\d{8}T\d{6})Z?$/', $until, $match) === 1) {
            return CarbonImmutable::createFromFormat('Ymd\THis', $match[1], 'UTC')->setTimezone($timezone)->toDateString();
        }

        throw new UnsupportedRecurrence('The end date of this repeating event could not be read.');
    }
}
