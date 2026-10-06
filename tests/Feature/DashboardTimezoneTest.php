<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard passes the selected timezone to the page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/?timezone=Europe/Istanbul')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('timezone', 'Europe/Istanbul'));
});

test('dashboard defaults the timezone to America/New_York', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('timezone', 'America/New_York'));
});
