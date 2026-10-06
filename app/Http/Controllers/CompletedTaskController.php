<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompletedTaskController extends Controller
{
    /**
     * Delete every completed task the dashboard shows (those in the Inbox or in an active
     * responsibility), along with their calendar sessions. Tasks in archived responsibilities are
     * hidden from the dashboard, so they are left alone.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->tasks()
            ->whereNotNull('completed_at')
            ->where(function ($query) {
                $query->whereNull('responsibility_id')->orWhereHas('responsibility', function ($responsibility) {
                    $responsibility->whereNull('archived_at');
                });
            })
            ->get()
            ->each->delete();

        return back();
    }
}
