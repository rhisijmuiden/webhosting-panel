<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(Locales::available()))]]);

        if ($user = $request->user()) {
            $user->update(['locale' => $data['locale']]);
        } else {
            $request->session()->put('locale', $data['locale']);
        }

        return back();
    }
}
