<?php

use App\Models\User;

test('a responsibility can be created with a color', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/responsibilities', ['name' => 'Home', 'color' => '#4f9d69'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('responsibilities', ['user_id' => $user->id, 'name' => 'Home', 'color' => '#4f9d69']);
});

test('a responsibility color must be a hex value', function () {
    $this->actingAs(User::factory()->create())
        ->post('/responsibilities', ['name' => 'Home', 'color' => 'red'])
        ->assertSessionHasErrors('color');
});

test('a responsibility name is required', function () {
    $this->actingAs(User::factory()->create())
        ->post('/responsibilities', ['name' => ''])
        ->assertSessionHasErrors('name');
});
