<?php

use App\Services\GoogleCalendar\RecurrenceParser;
use App\Services\GoogleCalendar\UnsupportedRecurrence;
use Carbon\CarbonImmutable;

function firstOccurrence(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-06 08:00:00', 'America/New_York'); // a Tuesday
}

test('a weekly rule gives its weekdays in order', function () {
    expect(RecurrenceParser::parse(['RRULE:FREQ=WEEKLY;BYDAY=TH,TU'], firstOccurrence()))->toBe(['days' => [2, 4], 'until' => null]);
});

test('a weekly rule without weekdays repeats on the weekday it starts', function () {
    expect(RecurrenceParser::parse(['RRULE:FREQ=WEEKLY'], firstOccurrence())['days'])->toBe([2]);
});

test('a daily rule repeats every day unless weekdays are given', function () {
    expect(RecurrenceParser::parse(['RRULE:FREQ=DAILY'], firstOccurrence())['days'])->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(RecurrenceParser::parse(['RRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR'], firstOccurrence())['days'])->toBe([1, 2, 3, 4, 5]);
});

test('an end date is read as a date or as a UTC time converted to the local day', function () {
    expect(RecurrenceParser::parse(['RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261231'], firstOccurrence())['until'])->toBe('2026-12-31')
        // 04:59:59 UTC on Dec 31 is 23:59:59 on Dec 30 in New York, the last local day.
        ->and(RecurrenceParser::parse(['RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261231T045959Z'], firstOccurrence())['until'])->toBe('2026-12-30');
});

test('other lines such as exclusions are ignored and the rule is case insensitive', function () {
    $rule = RecurrenceParser::parse(['EXDATE;TZID=America/New_York:20261013T080000', 'rrule:freq=weekly;byday=tu'], firstOccurrence());

    expect($rule['days'])->toBe([2]);
});

test('rules a routine cannot express are refused with a reason', function (array $recurrence, string $message) {
    expect(fn () => RecurrenceParser::parse($recurrence, firstOccurrence()))->toThrow(UnsupportedRecurrence::class, $message);
})->with([
    'a fixed number of repeats' => [['RRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=10'], 'fixed number of times'],
    'every other week' => [['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU'], 'every 2 weeks'],
    'every third day' => [['RRULE:FREQ=DAILY;INTERVAL=3'], 'every 3 days'],
    'monthly' => [['RRULE:FREQ=MONTHLY;BYMONTHDAY=5'], 'repeats monthly'],
    'an unsupported detail on a weekly rule' => [['RRULE:FREQ=WEEKLY;BYSETPOS=1'], 'do not support yet'],
    'yearly' => [['RRULE:FREQ=YEARLY'], 'yearly'],
    'the second Tuesday' => [['RRULE:FREQ=WEEKLY;BYDAY=2TU'], 'second Tuesday'],
    'a rule with no frequency' => [['RRULE:BYDAY=TU'], 'in another way'],
    'no rule at all' => [['EXDATE:20261013T120000Z'], 'single repeat rule'],
    'two rules' => [['RRULE:FREQ=WEEKLY;BYDAY=TU', 'RRULE:FREQ=WEEKLY;BYDAY=TH'], 'single repeat rule'],
    'an end date that cannot be read' => [['RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=soon'], 'could not be read'],
]);
