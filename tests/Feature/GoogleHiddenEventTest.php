<?php

use App\Models\GoogleEventImport;
use Inertia\Testing\AssertableInertia as Assert;

function hidePayload(array $overrides = []): array
{
    return array_merge(['calendar_id' => 'primary-id', 'event_id' => 'ev1', 'scope' => 'event', 'recurring_event_id' => null, 'title' => 'Dentist'], $overrides);
}

describe('hiding a Google event from the planner', function () {
    test('a hidden event leaves the calendar and nothing changes in Google', function () {
        fakeGoogle(events: [timedEvent(), allDayEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Hid the event from your planner.');

        expect(overlayTitles($user))->toBe(['Conference'])
            ->and(googleWrites())->toHaveCount(0)
            ->and($user->googleEventImports()->firstOrFail()->only(['kind', 'google_event_id', 'label', 'item_id']))
            ->toBe(['kind' => 'hidden', 'google_event_id' => 'ev1', 'label' => 'Dentist', 'item_id' => 0]);
    });

    test('hiding a whole series hides every event of it', function () {
        fakeGoogle(events: overlayEvents());
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload([
            'event_id' => 'series1_20261013T120000Z', 'scope' => 'series', 'recurring_event_id' => 'series1', 'title' => 'Prepare breakfast',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Hid all events of the series from your planner.');

        expect(overlayTitles($user))->toBe(['Dentist', 'Conference'])
            ->and($user->googleEventImports()->firstOrFail()->label)->toBe('Prepare breakfast (all events)');
    });

    test('hiding the same event twice keeps one record', function () {
        fakeGoogle();
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();

        expect($user->googleEventImports()->count())->toBe(1);
    });

    test('hiding is validated', function (array $overrides, string $field) {
        fakeGoogle();

        $this->actingAs(importer())->post('/integrations/google/hidden', hidePayload($overrides))->assertSessionHasErrors($field);
    })->with([
        'a series without its id' => [['scope' => 'series', 'recurring_event_id' => null], 'recurring_event_id'],
        'an unknown scope' => [['scope' => 'everything'], 'scope'],
        'no event' => [['event_id' => ''], 'event_id'],
    ]);

    test('hidden events are listed in settings and can be shown again', function () {
        fakeGoogle(events: [timedEvent()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $hidden = $user->googleEventImports()->firstOrFail();

        $this->actingAs($user)->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page->where('hiddenEvents', [['id' => $hidden->id, 'label' => 'Dentist']]));

        $this->actingAs($user)->delete("/integrations/google/hidden/{$hidden->id}")
            ->assertSessionHas('status', 'The event is shown in your planner again.');

        expect(overlayTitles($user))->toBe(['Dentist']);
        $this->actingAs($user)->get('/integrations/google')->assertInertia(fn (Assert $page) => $page->where('hiddenEvents', []));
    });

    test('only a hidden event of your own can be shown again', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $owner = importer();
        $this->actingAs($owner)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $hidden = $owner->googleEventImports()->firstOrFail();

        $this->actingAs(importer())->delete("/integrations/google/hidden/{$hidden->id}")->assertNotFound();

        // A planner item copied from an event is not a hidden event either.
        $copy = $owner->googleEventImports()->create(['google_calendar_id' => 'primary-id', 'google_event_id' => 'ev9', 'kind' => 'task', 'item_id' => 5]);
        $this->actingAs($owner)->delete("/integrations/google/hidden/{$copy->id}")->assertNotFound();

        expect(GoogleEventImport::count())->toBe(2);
    });
});
