<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Client;

use Illuminate\View\View;
use Rhis\DnsMail\Http\Controllers\DomainActions;
use Rhis\DnsMail\Models\MailDomain;

class MailDomainController extends DomainActions
{
    protected function area(): string
    {
        return 'client';
    }

    /** Customers only reach their own domains; anything else is a 404. */
    protected function findDomain(int|string $id): MailDomain
    {
        return MailDomain::where('customer_id', auth()->user()->customer_id)->findOrFail($id);
    }

    public function index(): View
    {
        $domains = MailDomain::where('customer_id', auth()->user()->customer_id)->with('onboarding')->withCount('mailboxes')->orderBy('domain')->get();

        return view('dns-mail::client.index', ['domains' => $domains]);
    }
}
