<?php

use App\Models\Routine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function weekOf(string $mondayInNewYork): array
{
    $from = CarbonImmutable::parse($mondayInNewYork, 'America/New_York')->startOfDay();

    return [$from, $from->addWeek()];
}

function breakfastRoutine(array $attributes = []): Routine
{
    return Routine::factory()->create(array_merge(['title' => 'Prepare breakfast'], $attributes));
}

test('a routine on Tuesday and Thursday produces those two days at 8 AM local time', function () {
    $routine = breakfastRoutine();
    [$from, $to] = weekOf('2026-10-05');

    $occurrences = $routine->load('occurrences')->occurrencesBetween($from, $to);

    expect(array_column($occurrences, 'occurs_on'))->toBe(['2026-10-06', '2026-10-08'])
        ->and($occurrences[0]['starts_at'])->toBe('2026-10-06T12:00:00+00:00') // 8:00 AM EDT
        ->and($occurrences[0]['ends_at'])->toBe('2026-10-06T13:00:00+00:00')
        ->and($occurrences[0]['title'])->toBe('Prepare breakfast');
});

test('the local time stays at 8 AM when daylight saving ends', function () {
    $routine = breakfastRoutine();

    [$beforeFrom, $beforeTo] = weekOf('2026-10-26');
    [$afterFrom, $afterTo] = weekOf('2026-11-02');

    $before = $routine->load('occurrences')->occurrencesBetween($beforeFrom, $beforeTo);
    $after = $routine->occurrencesBetween($afterFrom, $afterTo);

    expect($before[0]['starts_at'])->toBe('2026-10-27T12:00:00+00:00') // EDT, UTC-4
        ->and($after[0]['starts_at'])->toBe('2026-11-03T13:00:00+00:00'); // EST, UTC-5
});

test('a routine without an end date keeps going into the distant future', function () {
    $routine = breakfastRoutine();
    [$from, $to] = weekOf('2031-03-03');

    expect($routine->load('occurrences')->occurrencesBetween($from, $to))->toHaveCount(2);
});

test('a routine does not appear before its start date or after its end date', function () {
    $routine = breakfastRoutine(['starts_on' => '2026-10-07', 'ends_on' => '2026-10-13']);

    [$first, $firstEnd] = weekOf('2026-10-05');
    [$second, $secondEnd] = weekOf('2026-10-12');
    [$third, $thirdEnd] = weekOf('2026-10-19');

    $routine->load('occurrences');

    expect(array_column($routine->occurrencesBetween($first, $firstEnd), 'occurs_on'))->toBe(['2026-10-08'])
        ->and(array_column($routine->occurrencesBetween($second, $secondEnd), 'occurs_on'))->toBe(['2026-10-13'])
        ->and($routine->occurrencesBetween($third, $thirdEnd))->toBe([]);
});

test('a skipped day is omitted and a completed day is flagged', function () {
    $routine = breakfastRoutine();
    $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'skipped' => true]);
    $routine->occurrences()->create(['occurs_on' => '2026-10-08', 'completed_at' => now()]);
    [$from, $to] = weekOf('2026-10-05');

    $occurrences = $routine->load('occurrences')->occurrencesBetween($from, $to);

    expect($occurrences)->toHaveCount(1)
        ->and($occurrences[0]['occurs_on'])->toBe('2026-10-08')
        ->and($occurrences[0]['completed'])->toBeTrue();
});

test('a moved occurrence appears at its new time', function () {
    $routine = breakfastRoutine();
    $routine->occurrences()->create([
        'occurs_on' => '2026-10-06',
        'starts_at' => '2026-10-06 14:00:00',
        'ends_at' => '2026-10-06 15:00:00',
    ]);
    [$from, $to] = weekOf('2026-10-05');

    $occurrences = $routine->load('occurrences')->occurrencesBetween($from, $to);

    expect($occurrences[0]['starts_at'])->toBe('2026-10-06T14:00:00+00:00')
        ->and($occurrences[0]['moved'])->toBeTrue();
});

test('an occurrence moved to another week shows there and not in its original week', function () {
    $routine = breakfastRoutine();
    $routine->occurrences()->create([
        'occurs_on' => '2026-10-06',
        'starts_at' => '2026-10-14 14:00:00',
        'ends_at' => '2026-10-14 15:00:00',
    ]);

    [$original, $originalEnd] = weekOf('2026-10-05');
    [$later, $laterEnd] = weekOf('2026-10-12');

    $routine->load('occurrences');

    expect(array_column($routine->occurrencesBetween($original, $originalEnd), 'occurs_on'))->toBe(['2026-10-08'])
        ->and(array_column($routine->occurrencesBetween($later, $laterEnd), 'occurs_on'))->toBe(['2026-10-13', '2026-10-06', '2026-10-15']); // ordered by start time
});

test('the dashboard sends routine occurrences for the week and for today', function () {
    Carbon::setTestNow('2026-10-06 14:00:00'); // Tuesday 10:00 AM in New York
    $user = User::factory()->create();
    $responsibility = $user->responsibilities()->create(['name' => 'Blue Mosque', 'color' => '#4f9d69']);
    $routine = Routine::factory()->for($user)->create(['title' => 'Prepare breakfast', 'responsibility_id' => $responsibility->id]);
    $routine->occurrences()->create(['occurs_on' => '2026-10-13', 'skipped' => true]);

    $this->actingAs($user)
        ->get('/?week=2026-10-05&timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page
            ->has('routineSessions', 2)
            ->where('routineSessions.0.title', 'Prepare breakfast')
            ->where('routineSessions.0.responsibility_name', 'Blue Mosque')
            ->where('routineSessions.0.color', '#4f9d69')
            ->where('routineSessions.0.occurs_on', '2026-10-06')
            ->has('routinesToday', 1)
            ->where('routinesToday.0.occurs_on', '2026-10-06')
            ->has('routines', 1)
            ->where('routines.0.days', [2, 4])
            ->where('routines.0.start_time', '08:00')
            ->where('routines.0.skipped_dates', ['2026-10-13']));
});

test('the dashboard only sends the signed in users routines', function () {
    $user = User::factory()->create();
    Routine::factory()->create(['title' => 'Someone else']);

    $this->actingAs($user)
        ->get('/?week=2026-10-05&timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page->has('routines', 0)->has('routineSessions', 0));
});

test('guests cannot create routines', function () {
    $this->post('/routines', [])->assertRedirect('/login');
});

function routinePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Prepare breakfast',
        'days' => [4, 2, 2],
        'start_time' => '08:00',
        'duration_minutes' => 60,
        'timezone' => 'America/New_York',
        'starts_on' => '2026-10-05',
        'ends_on' => null,
    ], $overrides);
}

test('a routine can be created with sorted unique days and no end date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/routines', routinePayload(['days' => [4, 2]]))->assertSessionHasNoErrors();

    $routine = $user->routines()->firstOrFail();

    expect($routine->days)->toBe([2, 4])
        ->and($routine->start_time)->toBe('08:00:00')
        ->and($routine->duration_minutes)->toBe(60)
        ->and($routine->timezone)->toBe('America/New_York')
        ->and($routine->ends_on)->toBeNull();
});

test('a routine can belong to one of the users responsibilities', function () {
    $user = User::factory()->create();
    $responsibility = $user->responsibilities()->create(['name' => 'Blue Mosque']);

    $this->actingAs($user)->post('/routines', routinePayload(['responsibility_id' => $responsibility->id, 'days' => [2]]));

    expect($user->routines()->firstOrFail()->responsibility_id)->toBe($responsibility->id);
});

test('a routine cannot use another users responsibility', function () {
    $other = User::factory()->create()->responsibilities()->create(['name' => 'Private']);

    $this->actingAs(User::factory()->create())
        ->post('/routines', routinePayload(['responsibility_id' => $other->id]))
        ->assertSessionHasErrors('responsibility_id');
});

test('routine validation rejects bad input', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->create())
        ->post('/routines', routinePayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    'no days' => [['days' => []], 'days'],
    'a day that is not a weekday' => [['days' => [8]], 'days.0'],
    'a bad time' => [['start_time' => '8am'], 'start_time'],
    'too short' => [['duration_minutes' => 1], 'duration_minutes'],
    'a bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    'ending before it starts' => [['ends_on' => '2026-10-01'], 'ends_on'],
    'no title' => [['title' => ''], 'title'],
]);

test('editing a routine changes all occurrences and restores moved ones but keeps skipped days', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'starts_at' => '2026-10-06 14:00:00', 'ends_at' => '2026-10-06 15:00:00']);
    $routine->occurrences()->create(['occurs_on' => '2026-10-08', 'skipped' => true]);

    $this->actingAs($user)
        ->patch("/routines/{$routine->id}", routinePayload(['title' => 'Cook breakfast', 'start_time' => '07:30', 'days' => [2, 3, 4]]))
        ->assertSessionHasNoErrors();

    $routine->refresh();

    expect($routine->title)->toBe('Cook breakfast')
        ->and($routine->start_time)->toBe('07:30:00')
        ->and($routine->days)->toBe([2, 3, 4])
        ->and($routine->occurrences()->where('occurs_on', '2026-10-06')->first()->starts_at)->toBeNull()
        ->and($routine->occurrences()->where('occurs_on', '2026-10-08')->first()->skipped)->toBeTrue();
});

test('a routine can be deleted with its exceptions', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'skipped' => true]);

    $this->actingAs($user)->delete("/routines/{$routine->id}")->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('routines', ['id' => $routine->id]);
    $this->assertDatabaseMissing('routine_occurrences', ['routine_id' => $routine->id]);
});

test('another users routine cannot be edited or deleted', function () {
    $routine = Routine::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->patch("/routines/{$routine->id}", routinePayload(['days' => [2, 4]]))->assertNotFound();
    $this->actingAs($stranger)->delete("/routines/{$routine->id}")->assertNotFound();
    $this->actingAs($stranger)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['skipped' => true])->assertNotFound();
});

test('a single occurrence can be skipped and restored', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['skipped' => true])->assertSessionHasNoErrors();
    expect($routine->occurrences()->where('occurs_on', '2026-10-06')->first()->skipped)->toBeTrue();

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['skipped' => false]);
    expect($routine->occurrences()->count())->toBe(0);
});

test('a single occurrence can be marked done and not done', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['completed' => true]);
    expect($routine->occurrences()->firstOrFail()->completed_at)->not->toBeNull();

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['completed' => false]);
    expect($routine->occurrences()->count())->toBe(0);
});

test('a single occurrence can be moved and moved back', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['starts_at' => '2026-10-07T15:00:00.000Z', 'ends_at' => '2026-10-07T16:00:00.000Z'])
        ->assertSessionHasNoErrors();

    $moved = $routine->occurrences()->firstOrFail();
    expect($moved->starts_at->toIso8601String())->toBe('2026-10-07T15:00:00+00:00');

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-06", ['starts_at' => null, 'ends_at' => null]);
    expect($routine->occurrences()->count())->toBe(0);
});

test('a move needs both times and a sensible length', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();
    $url = "/routines/{$routine->id}/occurrences/2026-10-06";

    $this->actingAs($user)->patch($url, ['starts_at' => '2026-10-07T15:00:00Z'])->assertSessionHasErrors('ends_at');
    $this->actingAs($user)->patch($url, ['starts_at' => '2026-10-07T15:00:00Z', 'ends_at' => '2026-10-07T14:00:00Z'])->assertSessionHasErrors('ends_at');
    $this->actingAs($user)->patch($url, ['starts_at' => '2026-10-07T15:00:00Z', 'ends_at' => '2026-10-09T15:00:00Z'])->assertSessionHasErrors('ends_at');
});

test('a day the routine does not run on cannot be changed', function () {
    $user = User::factory()->create();
    $routine = Routine::factory()->for($user)->create();

    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-10-07", ['skipped' => true])->assertNotFound(); // Wednesday
    $this->actingAs($user)->patch("/routines/{$routine->id}/occurrences/2026-09-29", ['skipped' => true])->assertNotFound(); // before it starts
});
