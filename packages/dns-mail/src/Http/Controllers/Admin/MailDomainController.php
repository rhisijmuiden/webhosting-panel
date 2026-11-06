<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\DnsMail\Http\Controllers\DomainActions;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\MailPusher;

class MailDomainController extends DomainActions
{
    protected function area(): string
    {
        return 'admin';
    }

    protected function findDomain(int|string $id): MailDomain
    {
        return MailDomain::findOrFail($id);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $domains = MailDomain::with('customer')->withCount('mailboxes')
            ->when($q !== '', fn ($query) => $query->where('domain', 'like', '%' . $q . '%'))
            ->orderBy('domain')->paginate(50)->withQueryString();

        return view('dns-mail::admin.index', ['domains' => $domains, 'q' => $q, 'hasCloudflare' => $this->config->hasCloudflare()]);
    }

    public function create(): View
    {
        return view('dns-mail::admin.create', ['customers' => Customer::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'domain' => ['required', 'string', 'max:190', 'regex:/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', Rule::unique('mail_domains', 'domain')],
            'provider' => ['required', Rule::in(['rhis', 'microsoft365', 'external'])],
        ]);
        $domain = MailDomain::create(['domain' => strtolower($data['domain'])] + $data);
        ActivityLog::record('mail.domain_created', $domain->domain, [], $domain->customer_id);
        $warnings = $this->provisioner->afterChange($domain, withDkim: true);

        return redirect()->route('admin.mail.domains.show', $domain)->with('status', __('Domain added.'))->with('warnings', $warnings);
    }

    public function destroy(Request $request, int|string $mailDomain, MailPusher $pusher): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $request->validate(['confirm' => ['required', Rule::in([$domain->domain])]], ['confirm.in' => __('Type the domain name to confirm.')]);
        $name = $domain->domain;
        $domain->delete();
        ActivityLog::record('mail.domain_deleted', $name);
        try {
            $pusher->push();
        } catch (\Throwable) {
            // Shown on the next save; the domain row is gone either way.
        }

        return redirect()->route('admin.mail.domains.index')->with('status', __('Domain :domain removed. DNS records at Cloudflare were left in place.', ['domain' => $name]));
    }
}
