<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\WebsiteManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebsiteController extends Controller
{
    public function __construct(private readonly WebsiteManager $manager) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $websites = Website::with('customer')
            ->when($search, fn ($q) => $q->where('domain', 'like', "%{$search}%"))
            ->when($request->query('php'), fn ($q, $v) => $q->where('php_version', $v))
            ->orderBy('domain')->paginate(25)->withQueryString();

        return view('admin.websites.index', compact('websites', 'search'));
    }

    public function create(Request $request): View
    {
        return view('admin.websites.create', [
            'customers' => Customer::orderBy('name')->get(),
            'selectedCustomer' => $request->query('customer'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i', 'unique:websites,domain'],
            'profile' => ['required', Rule::in(Website::PROFILES)],
            'php_version' => ['required', Rule::in(Website::PHP_VERSIONS)],
        ]);
        $data['domain'] = strtolower($data['domain']);
        $data['page_cache'] = $request->boolean('page_cache');
        $data['ssl'] = $request->boolean('ssl');
        $data['www_alias'] = $request->boolean('www_alias');

        $customer = Customer::findOrFail($data['customer_id']);
        if (! $customer->canAddWebsite()) {
            return back()->withInput()->withErrors(['domain' => __('The package of this customer has no room for another website.')]);
        }
        $website = $this->manager->create($customer, $data);

        return redirect()->route('admin.websites.show', $website)->with('status', __('The website is being set up.'));
    }

    public function show(Website $website): View
    {
        return view('admin.websites.show', ['website' => $website->load('customer.package', 'databases')]);
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $data = $request->validate([
            'php_version' => ['sometimes', Rule::in(Website::PHP_VERSIONS)],
            'page_cache' => ['sometimes', 'boolean'],
        ]);
        try {
            if (isset($data['php_version']) && $data['php_version'] !== $website->php_version) {
                $this->manager->setPhp($website, $data['php_version']);
            }
            if (array_key_exists('page_cache', $data) && (bool) $data['page_cache'] !== $website->page_cache) {
                $this->manager->setCache($website, (bool) $data['page_cache']);
            }
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', __('Website saved.'));
    }

    public function ssl(Website $website): RedirectResponse
    {
        try {
            $this->manager->issueSsl($website);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', __('Certificate requested.'));
    }

    public function destroy(Request $request, Website $website): RedirectResponse
    {
        $request->validate(['confirm' => ['required', Rule::in([$website->domain])]], [
            'confirm.in' => __('Type the domain name to confirm.'),
        ]);
        $this->manager->remove($website, $request->boolean('keep_files'));

        return redirect()->route('admin.websites.index')->with('status', __('The website is being removed.'));
    }
}
