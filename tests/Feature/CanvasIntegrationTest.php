<?php

use App\Models\CanvasAccount;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('requires a login for every Canvas page', function () {
    $this->get('/integrations/canvas')->assertRedirect('/login');
    $this->post('/integrations/canvas')->assertRedirect('/login');
    $this->post('/integrations/canvas/sync')->assertRedirect('/login');
    $this->get('/school')->assertRedirect('/login');
});

it('shows Canvas as not connected, and lists it on the Integrations page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/integrations/canvas')
        ->assertInertia(fn (Assert $page) => $page->component('settings/canvas')->where('state', 'not_connected')->where('account', null));

    $this->actingAs($user)->get('/integrations')
        ->assertInertia(fn (Assert $page) => $page->where('integrations.1.key', 'canvas')->where('integrations.1.state', 'not_connected'));
});

it('connects with a token, stores it encrypted and runs the first sync', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/integrations/canvas', ['address' => 'njit.instructure.com', 'token' => ' secret-token '])
        ->assertRedirect('/integrations/canvas');

    $account = $user->canvasAccount;
    expect($account->base_url)->toBe('https://njit.instructure.com')
        ->and($account->access_token)->toBe('secret-token')
        ->and($account->canvas_user_name)->toBe('Sam Student')
        ->and(DB::table('canvas_accounts')->value('access_token'))->not->toContain('secret-token')
        ->and(Task::where('title', 'Homework 1')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('never sends a write to Canvas', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);

    $this->actingAs(User::factory()->create())->post('/integrations/canvas', ['address' => 'njit.instructure.com', 'token' => 'secret-token']);

    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('does not save a token that Canvas rejects', function () {
    fakeCanvas(failures: ['/users/self/profile' => 401]);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/integrations/canvas', ['address' => 'njit.instructure.com', 'token' => 'wrong'])
        ->assertSessionHasErrors('token');

    expect(CanvasAccount::count())->toBe(0);
});

it('refuses an address that is not a Canvas site over https', function (string $address) {
    Http::fake();

    $this->actingAs(User::factory()->create())->post('/integrations/canvas', ['address' => $address, 'token' => 'secret-token'])
        ->assertSessionHasErrors('address');

    Http::assertNothingSent();
})->with(['http://njit.instructure.com', 'evil.example.com', 'njit.instructure.com.evil.com', 'https://njit.instructure.com:8443', 'instructure.com', 'user@njit.instructure.com']);

it('accepts the address with or without https and a trailing slash', function (string $address) {
    fakeCanvas([canvasCourse()]);

    $this->actingAs($user = User::factory()->create())->post('/integrations/canvas', ['address' => $address, 'token' => 'secret-token'])
        ->assertSessionHasNoErrors();

    expect($user->canvasAccount->base_url)->toBe('https://njit.instructure.com');
})->with(['njit.instructure.com', 'https://njit.instructure.com/', 'HTTPS://NJIT.instructure.com/courses']);

it('shows the connection, courses and a reconnect state', function () {
    $user = canvasUser();
    $user->canvasAccount->update(['needs_reconnect' => true, 'last_error' => 'Canvas rejected the access token.']);

    $this->actingAs($user)->get('/integrations/canvas')
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'needs_reconnect')
            ->where('account.base_url', 'https://njit.instructure.com')
            ->where('courses.0.name', 'Data Structures')
            ->missing('account.access_token'));
});

it('lets a new token replace one that stopped working', function () {
    fakeCanvas([canvasCourse()]);
    $user = canvasUser();
    $user->canvasAccount->update(['needs_reconnect' => true]);

    $this->actingAs($user)->post('/integrations/canvas', ['address' => 'njit.instructure.com', 'token' => 'fresh-token']);

    $account = $user->canvasAccount->fresh();
    expect($account->needs_reconnect)->toBeFalse()->and($account->access_token)->toBe('fresh-token')->and(CanvasAccount::count())->toBe(1);
});

it('chooses which courses are tracked and syncs so tasks follow', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    $user = canvasUser();
    $course = $user->canvasCourses()->firstOrFail();

    $this->actingAs($user)->patch('/integrations/canvas', ['tracked_course_ids' => []])->assertRedirect();
    expect($course->fresh()->tracked)->toBeFalse()->and(Task::count())->toBe(0);

    $this->actingAs($user)->patch('/integrations/canvas', ['tracked_course_ids' => [$course->id]]);
    expect($course->fresh()->tracked)->toBeTrue()->and(Task::count())->toBe(1);
});

it('does not let one user track another user\'s course', function () {
    fakeCanvas([canvasCourse()]);
    $other = canvasUser();
    $other->canvasCourses()->update(['tracked' => false]);
    $user = User::factory()->create();
    CanvasAccount::factory()->for($user)->create();

    $this->actingAs($user)->patch('/integrations/canvas', ['tracked_course_ids' => [$other->canvasCourses()->value('id')]]);

    expect($other->canvasCourses()->value('tracked'))->toBeFalse();
});

it('syncs on demand', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    $user = canvasUser();

    $this->actingAs($user)->post('/integrations/canvas/sync')->assertRedirect()->assertSessionHas('status', 'Canvas is up to date.');

    expect(Task::count())->toBe(1);
});

it('reports a failed sync on demand', function () {
    fakeCanvas(failures: ['/api/v1/courses' => 500]);

    $this->actingAs(canvasUser())->post('/integrations/canvas/sync')->assertSessionHas('status', fn (string $status) => str_contains($status, 'error'));
});

it('disconnects but keeps the tasks and does not make them twice after reconnecting', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    $user = canvasUser();
    syncCanvas($user);

    $this->actingAs($user)->delete('/integrations/canvas')->assertRedirect('/integrations/canvas');
    expect($user->canvasAccount()->exists())->toBeFalse()->and(Task::count())->toBe(1);

    $this->actingAs($user)->post('/integrations/canvas', ['address' => 'njit.instructure.com', 'token' => 'secret-token']);
    expect(Task::count())->toBe(1);
});
