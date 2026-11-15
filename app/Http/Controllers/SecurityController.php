<?php

namespace App\Http\Controllers;

use App\Auth\ActiveSessions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __invoke(Request $request, ActiveSessions $sessions): Response
    {
        return Inertia::render('settings/security', [
            'sessions' => [
                'supported' => $sessions->supported(),
                'list' => $sessions->for($request->user(), $request->session()->getId()),
            ],
        ]);
    }
}
