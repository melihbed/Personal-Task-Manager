<?php

test('guests are redirected from the planner to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});
