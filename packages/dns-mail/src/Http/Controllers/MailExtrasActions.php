<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Rhis\DnsMail\MailExtras\ImportRunner;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailAutoreply;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailImport;
use Rhis\DnsMail\Services\DomainProvisioner;
use Throwable;

/**
 * Autoresponder, catch-all and IMAP import on the domain page. Shared by the
 * admin and the client area; the subclass decides which domains are reachable.
 */
abstract class MailExtrasActions extends Controller
{
    /** At most this many imports per domain wait or run at the same time. */
    public const MAX_ACTIVE_PER_DOMAIN = 5;

    public function __construct(
        protected readonly DomainProvisioner $provisioner,
        protected readonly ImportRunner $imports,
    ) {
    }

    abstract protected function area(): string; // admin | client

    abstract protected function findDomain(int|string $id): MailDomain;

    protected function tab(MailDomain $domain, string $tab): string
    {
        return route($this->area() . '.mail.domains.show', [$domain->id, 'tab' => $tab]);
    }

    private function localDomain(int|string $id): MailDomain
    {
        $domain = $this->findDomain($id);
        abort_unless($domain->hasLocalMail(), 404);

        return $domain;
    }

    // ------------------------------------------------------------ catch-all

    public function catchAll(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['off', 'mailbox', 'forward'])],
            'mailbox_id' => ['nullable', 'required_if:mode,mailbox', 'integer'],
            'targets' => ['nullable', 'required_if:mode,forward', 'string', 'max:2000'],
        ]);
        $catchAll = MailAlias::where('mail_domain_id', $domain->id)->where('local_part', '');
        if ($data['mode'] === 'off') {
            $catchAll->delete();
            ActivityLog::record('mail.catchall_off', '*@' . $domain->domain, [], $domain->customer_id);

            return $this->back($domain, 'mailboxes', __('Catch-all switched off: mail for unknown addresses is refused.'));
        }
        if ($data['mode'] === 'mailbox') {
            $targets = [$domain->mailboxes()->findOrFail((int) $data['mailbox_id'])->local_part . '@' . $domain->domain];
        } else {
            $targets = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower((string) $data['targets']))))));
            if ($targets === [] || count($targets) > 20) {
                throw ValidationException::withMessages(['targets' => __('Enter 1 to 20 addresses.')]);
            }
            foreach ($targets as $t) {
                if (!filter_var($t, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['targets' => __(':address is not a valid address.', ['address' => $t])]);
                }
                if (str_ends_with($t, '@' . $domain->domain) && !$domain->mailboxes()->where('local_part', strstr($t, '@', true))->exists()) {
                    throw ValidationException::withMessages(['targets' => __(':address is not a mailbox of this domain.', ['address' => $t])]);
                }
            }
        }
        MailAlias::updateOrCreate(['mail_domain_id' => $domain->id, 'local_part' => ''], ['targets' => $targets]);
        ActivityLog::record('mail.catchall_saved', '*@' . $domain->domain, ['targets' => $targets], $domain->customer_id);

        return $this->back($domain, 'mailboxes', __('Catch-all saved: mail for unknown addresses goes to :targets.', ['targets' => implode(', ', $targets)]));
    }

    // ------------------------------------------------------------ autoresponder

    public function autoreply(Request $request, int|string $mailDomain, int $mailbox): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $box = $domain->mailboxes()->findOrFail($mailbox);
        $enabled = $request->boolean('enabled');
        $bag = 'autoreply-' . $box->id;
        $data = $request->validateWithBag($bag, [
            'subject' => [$enabled ? 'required' : 'nullable', 'string', 'max:200'],
            'body' => [$enabled ? 'required' : 'nullable', 'string', 'max:10000'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => array_values(array_filter(['nullable', 'date_format:Y-m-d',
                $request->filled('starts_on') ? 'after_or_equal:starts_on' : null, $enabled ? 'after_or_equal:today' : null])),
            'interval_days' => ['required', 'integer', 'min:1', 'max:30'],
        ], [], ['subject' => __('subject'), 'body' => __('message'), 'starts_on' => __('first day'), 'ends_on' => __('last day')]);
        $address = $box->local_part . '@' . $domain->domain;
        $current = MailAutoreply::where('mailbox_id', $box->id)->first();
        MailAutoreply::updateOrCreate(['mailbox_id' => $box->id], [
            'enabled' => $enabled,
            'subject' => trim((string) ($data['subject'] ?? '')) ?: ($current?->subject ?? __('Out of office')),
            'body' => trim((string) ($data['body'] ?? '')) ?: ($current?->body ?? __('I am out of the office and will reply when I am back.')),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'interval_days' => (int) $data['interval_days'],
        ]);
        ActivityLog::record($enabled ? 'mail.autoreply_on' : 'mail.autoreply_off', $address, [], $domain->customer_id);

        return $this->back($domain, 'mailboxes', $enabled ? __('Autoreply for :address saved.', ['address' => $address]) : __('Autoreply for :address switched off.', ['address' => $address]));
    }

    // ------------------------------------------------------------ IMAP import

    private function sourceRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.?$/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'security' => ['required', Rule::in(MailImport::SECURITY)],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function testImport(Request $request, int|string $mailDomain): JsonResponse
    {
        $this->localDomain($mailDomain);
        $data = $request->validate($this->sourceRules());
        try {
            $result = $this->imports->test($data);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => trans_choice('Connected: :count message in :folders folders.|Connected: :count messages in :folders folders.', (int) ($result['messages'] ?? 0), [
                'count' => number_format((int) ($result['messages'] ?? 0), 0, ',', '.'), 'folders' => count($result['folders'] ?? []),
            ]),
            'messages' => (int) ($result['messages'] ?? 0),
            'folders' => $result['folders'] ?? [],
        ]);
    }

    public function storeImport(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $data = $request->validateWithBag('import', ['mailbox_id' => ['required', 'integer'], ...$this->sourceRules()], [], [
            'mailbox_id' => __('mailbox'), 'username' => __('user name'),
        ]);
        $box = $domain->mailboxes()->findOrFail((int) $data['mailbox_id']);
        if (MailImport::where('mailbox_id', $box->id)->whereIn('status', MailImport::ACTIVE)->exists()) {
            return back()->withInput($request->except('password'))->withErrors(['mailbox_id' => __('An import into this mailbox is already running.')], 'import');
        }
        if (MailImport::whereIn('mailbox_id', $domain->mailboxes()->pluck('id'))->whereIn('status', MailImport::ACTIVE)->count() >= self::MAX_ACTIVE_PER_DOMAIN) {
            return back()->withInput($request->except('password'))->withErrors(['mailbox_id' => __('At most :count imports per domain can run at the same time.', ['count' => self::MAX_ACTIVE_PER_DOMAIN])], 'import');
        }
        $import = MailImport::create([
            'mailbox_id' => $box->id,
            'created_by' => $request->user()?->id,
            'host' => rtrim(strtolower($data['host']), '.'),
            'port' => (int) $data['port'],
            'security' => $data['security'],
            'username' => $data['username'],
            'password' => $data['password'],
            'status' => 'queued',
        ]);
        $started = $this->imports->start($import);
        $import->refresh();
        if ($import->status === 'failed') {
            return redirect()->to($this->tab($domain, 'import'))->with('warnings', [__('The import could not start: :error', ['error' => $import->error])]);
        }

        return redirect()->to($this->tab($domain, 'import'))->with('status', $started
            ? __('Import into :address started.', ['address' => $box->address()])
            : __('The server is busy with other imports; this one starts as soon as there is room.'));
    }

    protected function findImport(MailDomain $domain, int $import): MailImport
    {
        return MailImport::whereIn('mailbox_id', $domain->mailboxes()->pluck('id'))->with('mailbox.domain')->findOrFail($import);
    }

    public function showImport(int|string $mailDomain, int $import): JsonResponse
    {
        $domain = $this->localDomain($mailDomain);

        return response()->json($this->imports->refresh($this->findImport($domain, $import))->toProgress());
    }

    public function stopImport(int|string $mailDomain, int $import): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $item = $this->findImport($domain, $import);
        try {
            $this->imports->stop($item);
        } catch (Throwable $e) {
            return redirect()->to($this->tab($domain, 'import'))->with('warnings', [$e->getMessage()]);
        }

        return redirect()->to($this->tab($domain, 'import'))->with('status', __('Import stopped. What was copied stays in the mailbox.'));
    }

    public function retryImport(Request $request, int|string $mailDomain, int $import): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $item = $this->findImport($domain, $import);
        if ($item->isActive()) {
            return redirect()->to($this->tab($domain, 'import'));
        }
        $data = $request->validateWithBag('retry-' . $item->id, ['password' => ['required', 'string', 'max:1024']]);
        $item->update(['password' => $data['password'], 'status' => 'queued', 'error' => null]);
        $started = $this->imports->start($item);
        $item->refresh();
        if ($item->status === 'failed') {
            return redirect()->to($this->tab($domain, 'import'))->with('warnings', [__('The import could not start: :error', ['error' => $item->error])]);
        }

        return redirect()->to($this->tab($domain, 'import'))->with('status', $started
            ? __('Import started again. Messages that are already there are skipped.')
            : __('The server is busy with other imports; this one starts as soon as there is room.'));
    }

    public function destroyImport(int|string $mailDomain, int $import): RedirectResponse
    {
        $domain = $this->localDomain($mailDomain);
        $this->imports->forget($this->findImport($domain, $import));

        return redirect()->to($this->tab($domain, 'import'))->with('status', __('Import removed from the list. The imported mail stays in the mailbox.'));
    }

    protected function back(MailDomain $domain, string $tab, string $status): RedirectResponse
    {
        $warnings = $this->provisioner->afterChange($domain, syncDns: false);

        return redirect()->to($this->tab($domain, $tab))->with('status', $status)->with('warnings', $warnings);
    }
}
