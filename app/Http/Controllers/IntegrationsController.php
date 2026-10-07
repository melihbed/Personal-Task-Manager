<?php

namespace App\Http\Controllers;

use App\Models\CanvasAccount;
use App\Models\GoogleAccount;
use App\Services\Canvas\CanvasBranding;
use App\Services\GoogleCalendar\GoogleCalendarBranding;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    /**
     * The Integrations page in Settings: one card per integration with its current state.
     */
    public function index(Request $request): Response
    {
        $account = $request->user()->googleAccount;
        $canvas = $request->user()->canvasAccount;

        return Inertia::render('settings/integrations', [
            'integrations' => [
                [
                    'key' => 'google-calendar',
                    'name' => 'Google Calendar',
                    'description' => 'Show your Google events next to your plan, and add your work sessions, routines and deadlines to Google Calendar.',
                    'icon' => GoogleCalendarBranding::icon(),
                    'legal' => GoogleCalendarBranding::LEGAL,
                    'href' => route('google.show'),
                    'state' => GoogleAccount::stateOf($account),
                    'detail' => $account?->email,
                ],
                [
                    'key' => 'canvas',
                    'name' => 'Canvas',
                    'description' => 'See upcoming and missing coursework from your school\'s Canvas, and get a task for every assignment.',
                    'icon' => CanvasBranding::icon(),
                    'legal' => CanvasBranding::LEGAL,
                    'href' => route('canvas.show'),
                    'state' => CanvasAccount::stateOf($canvas),
                    'detail' => $canvas?->canvas_user_name,
                ],
            ],
        ]);
    }
}
