<?php

namespace App\Http\Controllers;

use App\Dns\AddAppDomain;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppDomainController extends Controller
{
    public function store(Request $request, App $app, AddAppDomain $add): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:300'],
            'redirect' => ['required', Rule::in([AddAppDomain::NONE, AddAppDomain::WWW, AddAppDomain::APEX])],
        ]);

        $domains = $add($app, $data['name'], $data['redirect']);
        $names = implode(' and ', array_map(fn ($domain) => $domain->name, $domains));

        return back()->with('status', __(':names added. Create the DNS records below, then check again.', ['names' => $names]));
    }
}
