<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Team\TeamManager;
use App\Support\Locales;
use App\Support\TeamAreas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The owner's team page: invite people, choose what they can reach, see who changed what. */
class TeamController extends Controller
{
    public function __construct(private readonly TeamManager $team) {}

    public function index(Request $request): View
    {
        $customer = $this->customer($request);

        return view('client.team.index', [
            'customer' => $customer,
            'owners' => User::where('customer_id', $customer->id)->whereDoesntHave('teamMember')->orderBy('name')->get(),
            'members' => TeamMember::where('customer_id', $customer->id)->with(['user', 'websites', 'inviter'])->get()->sortBy(fn ($m) => $m->user->name),
            'invitations' => TeamInvitation::where('customer_id', $customer->id)->with('inviter')->latest()->get(),
            'websiteCount' => $customer->websites()->count(),
            'max' => (int) config('team.max_members', 25),
            'used' => $this->team->memberCount($customer),
        ]);
    }

    public function invite(Request $request): View
    {
        return view('client.team.invite', $this->formData($request) + ['member' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'locale' => ['required', Rule::in(array_keys(Locales::available()))],
        ] + $this->accessRules());
        $access = $this->accessFrom($request, $customer);
        $this->team->invite($customer, $request->user(), $data['email'], $data['name'] ?? null, $data['locale'], $access);

        return redirect()->route('client.team.index')->with('status', __('Invitation sent to :email.', ['email' => strtolower($data['email'])]));
    }

    public function resend(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->own($request, $invitation->customer_id);
        $this->team->resend($invitation);

        return back()->with('status', __('Invitation sent again to :email.', ['email' => $invitation->email]));
    }

    public function revoke(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->own($request, $invitation->customer_id);
        $this->team->revoke($invitation);

        return back()->with('status', __('Invitation withdrawn.'));
    }

    public function edit(Request $request, TeamMember $member): View
    {
        $this->own($request, $member->customer_id);

        return view('client.team.invite', $this->formData($request) + ['member' => $member->load('user', 'websites')]);
    }

    public function update(Request $request, TeamMember $member): RedirectResponse
    {
        $this->own($request, $member->customer_id);
        $request->validate($this->accessRules());
        $this->team->update($member, $this->accessFrom($request, $this->customer($request)));

        return redirect()->route('client.team.index')->with('status', __('Access of :name saved.', ['name' => $member->user->name]));
    }

    public function destroy(Request $request, TeamMember $member): RedirectResponse
    {
        $this->own($request, $member->customer_id);
        $name = $member->user->name;
        $this->team->remove($member);

        return redirect()->route('client.team.index')->with('status', __(':name was removed from your team.', ['name' => $name]));
    }

    public function settings(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $customer->forceFill(['team_require_two_factor' => $request->boolean('team_require_two_factor')])->save();
        ActivityLog::record('team.settings_changed', null, ['team_require_two_factor' => $customer->team_require_two_factor]);

        return back()->with('status', __('Team settings saved.'));
    }

    public function activity(Request $request): View
    {
        $customer = $this->customer($request);
        $people = User::where('customer_id', $customer->id)->orderBy('name')->get(['id', 'name', 'email']);
        $who = $request->integer('user') ?: null;
        $logs = ActivityLog::where('customer_id', $customer->id)
            ->when($who, fn ($q) => $q->where('user_id', $who))
            ->with('user')->latest('created_at')->latest('id')->paginate(50)->withQueryString();

        return view('client.team.activity', compact('logs', 'people', 'who'));
    }

    private function formData(Request $request): array
    {
        $customer = $this->customer($request);

        return [
            'customer' => $customer,
            'websites' => $customer->websites()->whereNull('parent_id')->orderBy('domain')->get(['id', 'domain']),
            'areas' => TeamAreas::all(),
            'wideAreas' => TeamManager::NEEDS_ALL_WEBSITES,
            'locales' => Locales::available(),
        ];
    }

    private function accessRules(): array
    {
        return [
            'all_websites' => ['nullable', 'boolean'],
            'websites' => ['array'],
            'websites.*' => ['integer'],
            'areas' => ['array'],
            'areas.*' => ['string', Rule::in(TeamAreas::keys())],
        ];
    }

    private function accessFrom(Request $request, Customer $customer): array
    {
        return $this->team->access(
            $customer,
            $request->boolean('all_websites'),
            (array) $request->input('websites', []),
            (array) $request->input('areas', []),
        );
    }

    private function customer(Request $request): Customer
    {
        return $request->user()->customer;
    }

    private function own(Request $request, int $customerId): void
    {
        abort_unless($customerId === $request->user()->customer_id, 404);
    }
}
