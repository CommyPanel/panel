<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Dns\ServerAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServerAddressController extends Controller
{
    public function update(Request $request, ServerAddress $server, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'ipv4' => ['nullable', 'ipv4'],
            'ipv6' => ['nullable', 'ipv6'],
        ]);

        if (blank($data['ipv4'] ?? null) && blank($data['ipv6'] ?? null)) {
            throw ValidationException::withMessages(['ipv4' => __('Enter at least an IPv4 or an IPv6 address.')]);
        }

        $before = ['ipv4' => $server->ipv4(), 'ipv6' => $server->ipv6(), 'source' => $server->source()];
        $server->save($data['ipv4'] ?? null, $data['ipv6'] ?? null, 'manual');
        $audit->record('server.address_set', $request->user(), ['ipv4' => $data['ipv4'] ?? null, 'ipv6' => $data['ipv6'] ?? null, 'before' => $before]);

        return back()->with('status', __('Server address saved.'));
    }

    public function detect(Request $request, ServerAddress $server, AuditLogger $audit): RedirectResponse
    {
        $found = $server->detect();

        if ($found['ipv4'] === null && $found['ipv6'] === null) {
            return back()->withErrors(['ipv4' => __('Could not find the public address of this server automatically. Enter it by hand.')]);
        }

        // A family that is not found keeps what it was, so a failed lookup of one does not erase the other.
        $server->save($found['ipv4'] ?? $server->ipv4(), $found['ipv6'] ?? $server->ipv6(), 'auto');
        $audit->record('server.address_detected', $request->user(), $found);

        return back()->with('status', __('Server address detected.'));
    }
}
