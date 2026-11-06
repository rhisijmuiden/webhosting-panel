<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $customers = Customer::with('package')->withCount('websites')
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('websites', fn ($w) => $w->where('domain', 'like', "%{$search}%"))))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.customers.index', compact('customers', 'search'));
    }

    public function create(): View
    {
        return view('admin.customers.form', ['customer' => new Customer(['locale' => 'en', 'status' => 'active'])] + $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));
        ActivityLog::record('customer.created', $customer->displayName(), customerId: $customer->id);

        return redirect()->route('admin.customers.show', $customer)->with('status', __('Customer created.'));
    }

    public function show(Customer $customer): View
    {
        $customer->load(['package', 'websites', 'users']);

        return view('admin.customers.show', [
            'customer' => $customer,
            'locales' => Locales::available(),
            'activity' => ActivityLog::where('customer_id', $customer->id)->latest('created_at')->limit(15)->get(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('admin.customers.form', compact('customer') + $this->formData());
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));
        ActivityLog::record('customer.updated', $customer->displayName(), customerId: $customer->id);

        return redirect()->route('admin.customers.show', $customer)->with('status', __('Customer saved.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->websites()->exists()) {
            return back()->withErrors(['customer' => __('Remove the websites of this customer first.')]);
        }
        ActivityLog::record('customer.deleted', $customer->displayName());
        $customer->delete();

        return redirect()->route('admin.customers.index')->with('status', __('Customer deleted.'));
    }

    public function storeUser(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
            'locale' => ['required', Rule::in(array_keys(Locales::available()))],
        ]);
        $customer->users()->create($data + ['role' => User::ROLE_CLIENT]);
        ActivityLog::record('customer.user_created', $data['email'], customerId: $customer->id);

        return back()->with('status', __('Login created.'));
    }

    public function destroyUser(Customer $customer, User $user): RedirectResponse
    {
        abort_unless($user->customer_id === $customer->id, 404);
        ActivityLog::record('customer.user_deleted', $user->email, customerId: $customer->id);
        $user->delete();

        return back()->with('status', __('Login deleted.'));
    }

    private function formData(): array
    {
        return [
            'packages' => HostingPackage::where('is_active', true)->orderBy('name')->get(),
            'locales' => Locales::available(),
        ];
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'crm_id' => ['nullable', 'string', 'max:64', Rule::unique('customers')->ignore($customer?->id)],
            'hosting_package_id' => ['nullable', 'exists:hosting_packages,id'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'locale' => ['required', Rule::in(array_keys(Locales::available()))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public function liftTrafficSuspension(Customer $customer, \App\Services\Traffic $traffic): RedirectResponse
    {
        // Keep the alert month: the websites stay online for the rest of this month.
        $count = $traffic->unsuspend($customer);

        return back()->with('status', trans_choice(':count website is online again.|:count websites are online again.', $count));
    }
}
