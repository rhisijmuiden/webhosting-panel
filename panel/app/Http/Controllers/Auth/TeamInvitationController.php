<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Services\Team\TeamManager;
use App\Support\TeamAreas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The page behind the link in the invitation: choose a name and password, and you are in. */
class TeamInvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->find($token);
        if ($invitation && ! $request->user() && ! $request->session()->has('locale')) {
            app()->setLocale($invitation->locale);
        }

        return view('auth.team-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'areas' => $invitation ? collect(TeamAreas::all())->only($invitation->areas)->map(fn ($a) => __($a['label']))->values() : collect(),
            'websites' => $invitation && ! $invitation->all_websites
                ? \App\Models\Website::withoutGlobalScopes()->whereIn('id', $invitation->website_ids)->orderBy('domain')->pluck('domain')
                : collect(),
        ]);
    }

    public function accept(Request $request, string $token, TeamManager $team): RedirectResponse
    {
        $invitation = $this->find($token);
        abort_if($invitation === null, 404);
        abort_if($request->user() !== null, 409);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        $user = $team->accept($invitation, $data['name'], $data['password']);

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return redirect()->route('client.dashboard')
            ->with('status', __('Welcome to the team of :customer.', ['customer' => $invitation->customer->displayName()]));
    }

    /** A valid, not expired invitation for this token, or null. */
    private function find(string $token): ?TeamInvitation
    {
        $invitation = strlen($token) === 48 ? TeamInvitation::findByToken($token) : null;

        return $invitation && ! $invitation->isExpired() ? $invitation : null;
    }
}
