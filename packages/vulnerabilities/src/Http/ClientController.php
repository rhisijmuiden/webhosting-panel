<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Vulnerabilities\Models\Contact;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\Vulnerabilities\Risk;
use Rhis\Vulnerabilities\Scanner;

final class ClientController extends Controller
{
    private function customer(Request $request): Customer
    {
        return Customer::findOrFail($request->user()->customer_id);
    }

    public function index(Request $request): View
    {
        $customer = $this->customer($request);
        $open = Finding::open()->forCustomer($customer->id)->with('website', 'install.website')->get()
            ->sortBy(fn (Finding $f) => [$f->isMalware() ? 0 : 1, Finding::rank($f->severity), $f->website->domain])->values();
        $resolved = Finding::where('status', 'resolved')->forCustomer($customer->id)->where('resolved_at', '>=', now()->subDays(30))
            ->with('website', 'install.website')->latest('resolved_at')->take(10)->get();
        $score = (int) round(min(100, $open->sum(fn (Finding $f) => $f->weight())));

        return view('vulnerabilities::client.index', [
            'customer' => $customer, 'open' => $open, 'groups' => $open->groupBy(fn (Finding $f) => $f->siteLabel()),
            'resolved' => $resolved, 'totals' => Risk::totals($customer->id), 'level' => Risk::level($score),
            'contact' => Contact::for($customer)->setRelation('customer', $customer),
            'toolkit' => (bool) config('wordpress.customer_toolkit'),
        ]);
    }

    public function scan(Request $request, Scanner $scanner): RedirectResponse
    {
        $customer = $this->customer($request);
        $found = $resolved = 0;
        foreach (Website::where('customer_id', $customer->id)->where('status', 'active')->get() as $site) {
            $r = $scanner->scanWebsite($site);
            $found += $r['found'];
            $resolved += $r['resolved'];
        }

        return back()->with('status', __('Checked again: :found new problems, :resolved resolved.', ['found' => $found, 'resolved' => $resolved]));
    }

    public function notifications(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'extra_emails' => ['nullable', 'string', 'max:1000'],
        ]);
        $emails = collect(preg_split('/[\s,;]+/', (string) ($data['extra_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($e) => strtolower($e))->unique();
        $bad = $emails->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad->isNotEmpty() || $emails->count() > 5) {
            return back()->withInput()->withErrors(['extra_emails' => __('Enter up to 5 valid mail addresses, separated by commas.')]);
        }
        Contact::updateOrCreate(['customer_id' => $customer->id], [
            'enabled' => (bool) ($data['enabled'] ?? false), 'extra_emails' => $emails->values()->all(),
        ]);
        ActivityLog::record('security.alert_contacts', $customer->displayName(), ['enabled' => (bool) ($data['enabled'] ?? false), 'extra' => $emails->count()]);

        return back()->with('status', __('Notification settings saved.'));
    }
}
