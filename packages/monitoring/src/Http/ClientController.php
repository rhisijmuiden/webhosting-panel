<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Http;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Rhis\Monitoring\Components;
use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Contact;
use Rhis\Monitoring\Models\Incident;
use Rhis\Monitoring\Models\Notice;
use Rhis\Monitoring\Options;
use Rhis\Monitoring\Uptime;

class ClientController extends Controller
{
    private function customer(Request $request): Customer
    {
        return Customer::findOrFail($request->user()->customer_id);
    }

    public function index(Request $request, Options $options): View
    {
        $customer = $this->customer($request);
        $websites = Website::where('customer_id', $customer->id)->orderBy('domain')->get(['id', 'domain', 'status', 'ssl_status']);
        $checks = Check::whereIn('website_id', $websites->pluck('id'))->get()->groupBy('website_id');
        $sites = $websites->map(function (Website $w) use ($checks) {
            $mine = $checks->get($w->id, collect())->keyBy('kind');
            $days = Uptime::days("site:{$w->id}", 90);

            return [
                'website' => $w, 'check' => $mine->get('website'), 'ssl' => $mine->get('ssl'), 'days' => $days,
                'uptime30' => Uptime::percent(array_slice($days, -30)), 'uptime90' => Uptime::percent($days),
            ];
        });

        return view('monitoring::client.index', [
            'customer' => $customer,
            'sites' => $sites,
            'components' => Components::overview(30),
            'notices' => Notice::visible()->latest('id')->get(),
            'incidents' => Incident::where('kind', 'website')->whereIn('website_id', $websites->pluck('id'))
                ->where('started_at', '>=', now()->subDays(30))->latest('started_at')->limit(20)->get(),
            'contact' => Contact::for($customer)->setRelation('customer', $customer),
            'alertsAllowed' => (bool) $options->get('customer_alerts'),
            'publicPage' => (bool) $options->get('status_page'),
        ]);
    }

    public function saveAlerts(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate(['enabled' => ['nullable', 'boolean'], 'extra_emails' => ['nullable', 'string', 'max:600']]);
        $emails = array_values(array_unique(preg_split('/[,;\s]+/', (string) ($data['extra_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        foreach ($emails as $m) {
            if (! filter_var($m, FILTER_VALIDATE_EMAIL)) {
                return back()->withErrors(['extra_emails' => __(':address is not a valid email address.', ['address' => $m])])->withInput();
            }
        }
        if (count($emails) > 5) {
            return back()->withErrors(['extra_emails' => __('Up to 5 addresses.')])->withInput();
        }
        Contact::updateOrCreate(['customer_id' => $customer->id], ['enabled' => $request->boolean('enabled'), 'extra_emails' => $emails]);

        return redirect()->to(route('client.monitoring.index').'#alerts')->with('status', __('Saved.'));
    }
}
