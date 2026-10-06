<?php

test('the postgres connection is pinned to UTC so saved times do not shift', function () {
    // Laravel writes UTC clock strings with no offset. If the database session uses any other timezone
    // (for example the developer machine's), every timestamptz value is reinterpreted and comes back shifted.
    // The test suite runs on SQLite, so this guards the setting itself.
    expect(config('database.connections.pgsql.timezone'))->toBe('UTC')
        ->and(config('app.timezone'))->toBe('UTC');
});
