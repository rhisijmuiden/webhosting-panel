<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Client;

use Rhis\DnsMail\Http\Controllers\MailExtrasActions;
use Rhis\DnsMail\Models\MailDomain;

class MailExtrasController extends MailExtrasActions
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
}
