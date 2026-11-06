<?php

namespace App\Http\Controllers;

use App\Databases\DatabaseException;
use App\Databases\RemoteAccess;
use App\Models\DatabaseServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Open a database server to the internet, change who may connect, close it, and give out the
 * public certificate of its authority. Every route needs `databases.remote` and a recent password.
 */
class DatabaseRemoteController extends Controller
{
    public function store(Request $request, DatabaseServer $server, RemoteAccess $access): RedirectResponse
    {
        [$allow, $minutes] = $this->input($request);

        try {
            $access->open($server, $allow, $minutes, $request->user());
        } catch (DatabaseException $e) {
            return back()->withErrors(['remote' => $e->getMessage()]);
        }

        return back()->with('status', 'Opening the server to the internet. If it has to start again with TLS this takes a moment.');
    }

    public function update(Request $request, DatabaseServer $server, RemoteAccess $access): RedirectResponse
    {
        [$allow, $minutes] = $this->input($request);

        try {
            $access->change($server, $allow, $minutes, $request->user());
        } catch (DatabaseException $e) {
            return back()->withErrors(['remote' => $e->getMessage()]);
        }

        return back()->with('status', 'Remote access changed.');
    }

    public function destroy(Request $request, DatabaseServer $server, RemoteAccess $access): RedirectResponse
    {
        try {
            $access->close($server, $request->user());
        } catch (DatabaseException $e) {
            return back()->withErrors(['remote' => $e->getMessage()]);
        }

        return back()->with('status', 'The server is closed to the internet.');
    }

    /** The certificate authority's public certificate, for the client's "trusted CA" setting. */
    public function ca(DatabaseServer $server): Response
    {
        abort_if($server->tls_ca === null, 404);

        return response($server->tls_ca, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="commypanel-'.$server->slug.'-ca.pem"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{string, int|null}
     */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'allow' => ['required', 'string', 'max:2000'],
            'duration' => ['nullable', 'integer'],
            'never' => ['boolean'],
            'acknowledge' => ['boolean'],
        ]);

        if ($request->boolean('never')) {
            // Open without an end: the person has to say they understand, every time.
            if (! $request->boolean('acknowledge')) {
                throw ValidationException::withMessages(['acknowledge' => 'Confirm that you understand the risk of leaving it open.']);
            }

            return [$data['allow'], null];
        }

        if (! isset($data['duration'])) {
            throw ValidationException::withMessages(['duration' => 'Choose how long it stays open.']);
        }

        return [$data['allow'], (int) $data['duration']];
    }
}
