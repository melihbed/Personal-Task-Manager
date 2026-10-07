<?php

namespace App\Http\Controllers;

use App\Services\Canvas\CanvasSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CanvasSyncController extends Controller
{
    /** Sync now: reads Canvas again straight away, so the result is on the page when it reloads. */
    public function store(Request $request, CanvasSync $sync): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->canvasAccount === null, 404);

        $sync->run($user);

        $error = $user->canvasAccount()->value('last_error');

        return back()->with('status', $error ?? 'Canvas is up to date.');
    }
}
