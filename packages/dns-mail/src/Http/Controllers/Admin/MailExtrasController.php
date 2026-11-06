<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\DnsMail\Http\Controllers\MailExtrasActions;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailImport;

class MailExtrasController extends MailExtrasActions
{
    protected function area(): string
    {
        return 'admin';
    }

    protected function findDomain(int|string $id): MailDomain
    {
        return MailDomain::findOrFail($id);
    }

    /** Every import on the server, newest first. */
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['active', 'done', 'problems'], true) ? $request->query('status') : null;
        $query = MailImport::with(['mailbox.domain.customer'])->latest('id');
        match ($status) {
            'active' => $query->whereIn('status', MailImport::ACTIVE),
            'done' => $query->where('status', 'done'),
            'problems' => $query->whereIn('status', ['partial', 'failed']),
            default => null,
        };
        $imports = $query->paginate(50)->withQueryString();
        $imports->getCollection()->where('status', 'running')->each(fn (MailImport $i) => $this->imports->refresh($i));

        return view('dns-mail::extras.admin-imports', [
            'imports' => $imports,
            'status' => $status,
            'counts' => [
                'active' => MailImport::whereIn('status', MailImport::ACTIVE)->count(),
                'done' => MailImport::where('status', 'done')->count(),
                'problems' => MailImport::whereIn('status', ['partial', 'failed'])->count(),
            ],
            'available' => $this->imports->available(),
        ]);
    }
}
