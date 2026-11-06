<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Subscription;
use App\Services\Agent\AgentException;
use App\Services\SubscriptionManager;
use App\Services\WebsiteManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Subscriptions (one website on one hosting package), like Plesk: a list with search,
 * sorting and paging, and bulk actions to change the package, owner, status or expiry
 * date, or to remove subscriptions.
 */
class SubscriptionController extends Controller
{
    public const PER_PAGE = [10, 25, 100];

    private const SORTS = ['domain' => 'websites.domain', 'owner' => 'owner_name', 'created' => 'subscriptions.created_at', 'expires' => 'subscriptions.expires_on'];

    public function __construct(private readonly SubscriptionManager $manager) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'domain';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $per = $request->query('per') === 'all' ? 'all' : (in_array((int) $request->query('per'), self::PER_PAGE, true) ? (int) $request->query('per') : 25);

        $query = Subscription::query()
            ->select('subscriptions.*')
            ->join('websites', 'websites.id', '=', 'subscriptions.website_id')
            ->join('customers', 'customers.id', '=', 'websites.customer_id')
            ->selectRaw('COALESCE(customers.company, customers.name) as owner_name')
            ->with(['website.customer', 'package'])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('websites.domain', 'like', "%{$search}%")
                ->orWhere('customers.name', 'like', "%{$search}%")
                ->orWhere('customers.company', 'like', "%{$search}%")
                ->orWhereHas('package', fn ($p) => $p->where('name', 'like', "%{$search}%"))))
            ->when($request->query('customer'), fn ($q, $id) => $q->where('websites.customer_id', $id))
            ->when($request->query('package'), fn ($q, $id) => $id === 'none' ? $q->whereNull('subscriptions.hosting_package_id') : $q->where('subscriptions.hosting_package_id', $id))
            ->when(in_array($request->query('status'), Subscription::STATUSES, true), fn ($q) => $q->where('subscriptions.status', $request->query('status')))
            ->orderBy(self::SORTS[$sort], $dir)->orderBy('websites.domain');

        $total = (clone $query)->count();
        $subscriptions = $query->paginate($per === 'all' ? max(1, $total) : $per)->withQueryString();

        return view('admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'per' => $per,
            'packages' => HostingPackage::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
            'filterCustomer' => $request->query('customer') ? Customer::find($request->query('customer')) : null,
        ]);
    }

    public function edit(Subscription $subscription): View
    {
        return view('admin.subscriptions.edit', [
            'subscription' => $subscription->load('website.customer', 'package'),
            'packages' => HostingPackage::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'hosting_package_id' => ['nullable', 'exists:hosting_packages,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'status' => ['required', Rule::in(Subscription::STATUSES)],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            $this->manager->changePackage($subscription, isset($data['hosting_package_id']) ? HostingPackage::find($data['hosting_package_id']) : null);
            $this->manager->changeOwner($subscription, Customer::findOrFail($data['customer_id']));
            if (($data['expires_on'] ?? null) !== $subscription->expires_on?->toDateString()) {
                $this->manager->setExpiry($subscription, $data['expires_on'] ?? null);
            }
            $this->status($subscription->fresh(), $data['status']);
            $subscription->update(['notes' => $data['notes'] ?? null]);
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => $e->getMessage()]);
        }

        return redirect()->route('admin.subscriptions.index')->with('status', __('Subscription saved.'));
    }

    /** Bulk actions on the selected subscriptions. */
    public function bulk(Request $request, WebsiteManager $websites): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['package', 'owner', 'activate', 'suspend', 'expiry', 'remove'])],
            'hosting_package_id' => ['nullable', 'exists:hosting_packages,id'],
            'customer_id' => ['required_if:action,owner', 'nullable', 'exists:customers,id'],
            'expires_on' => ['nullable', 'date'],
            'confirm' => ['exclude_unless:action,remove', 'required', 'accepted'],
        ], [
            'ids.required' => __('Select one or more subscriptions first.'),
            'confirm.required' => __('Confirm that the websites and their files are removed.'),
        ]);
        $subscriptions = Subscription::with('website', 'package')->whereIn('id', $data['ids'])->get();
        $done = 0;
        $errors = [];
        foreach ($subscriptions as $subscription) {
            try {
                match ($data['action']) {
                    'package' => $this->manager->changePackage($subscription, isset($data['hosting_package_id']) ? HostingPackage::find($data['hosting_package_id']) : null),
                    'owner' => $this->manager->changeOwner($subscription, Customer::findOrFail($data['customer_id'])),
                    'activate', 'suspend' => $this->status($subscription, $data['action'] === 'activate' ? 'active' : 'suspended'),
                    'expiry' => $this->manager->setExpiry($subscription, $data['expires_on'] ?? null),
                    'remove' => $subscription->website && $subscription->website->status !== 'removing'
                        ? $websites->remove($subscription->website, false) : null,
                };
                $done++;
            } catch (AgentException $e) {
                $errors[] = ($subscription->website?->domain ?? '#'.$subscription->id).': '.$e->getMessage();
            }
        }
        $message = $data['action'] === 'remove'
            ? trans_choice(':count subscription is being removed.|:count subscriptions are being removed.', $done)
            : trans_choice(':count subscription changed.|:count subscriptions changed.', $done);
        $back = back()->with('status', $message);

        return $errors ? $back->withErrors(['agent' => implode("\n", $errors)]) : $back;
    }

    private function status(Subscription $subscription, string $status): void
    {
        if ($status === $subscription->status) {
            return;
        }
        $status === 'active' ? $this->manager->activate($subscription) : $this->manager->suspend($subscription, Subscription::BY_ADMIN);
    }
}
