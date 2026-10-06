<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('forgot password page renders', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
});

test('reset link can be requested', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password page receives token and email', function () {
    $this->get('/reset-password/abc123?email=jane@example.com')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/reset-password')
            ->where('token', 'abc123')
            ->where('email', 'jane@example.com'));
});

test('password can be reset with a valid token', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-long-password',
        'password_confirmation' => 'a-new-long-password',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    expect(Hash::check('a-new-long-password', $user->fresh()->password))->toBeTrue();
});

test('password cannot be reset with an invalid token', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'invalid',
        'email' => $user->email,
        'password' => 'a-new-long-password',
        'password_confirmation' => 'a-new-long-password',
    ])->assertSessionHasErrors('email');
});
