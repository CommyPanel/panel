<?php

namespace App\Http\Controllers;

use App\I18n\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A person changes the language of their own panel. It touches no one else and needs no permission. */
class LanguageController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(array_keys(Locales::available()))]]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return back();
    }
}
