<?php

declare(strict_types=1);

namespace Rhis\Migration\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Models\MigrationSource;
use Rhis\Migration\Services\DnsCutover;
use Rhis\Migration\Services\Migrator;
use Throwable;

final class MigrationController extends Controller
{
    private const HOST = '/^((?!-)[A-Za-z0-9-]{1,63}(?<!-)\.)*(?!-)[A-Za-z0-9-]{1,63}(?<!-)$|^[0-9a-fA-F:.]{2,45}$/';

    public function __construct(private readonly AgentClient $agent, private readonly Migrator $migrator) {}

    public function index(): View
    {
        $errors = [];
        try {
            $key = $this->agent->call('migrate.key');
        } catch (AgentException $e) {
            $key = null;
            $errors[] = $e->getMessage();
        }

        return view('migration::admin.index', [
            'sources' => MigrationSource::withCount([
                'items',
                'items as done_count' => fn ($q) => $q->whereIn('status', ['live', 'done']),
                'items as active_count' => fn ($q) => $q->whereIn('status', ['queued', ...MigrationItem::ACTIVE]),
                'items as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])->orderBy('name')->get(),
            'key' => $key, 'agentErrors' => $errors, 'types' => MigrationSource::TYPES,
        ]);
    }

    public function storeSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(MigrationSource::TYPES))],
            'host' => ['required', 'string', 'max:253', 'regex:'.self::HOST],
            'port' => ['required', 'integer', 'between:1,65535'],
            'user' => ['required', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
        ]);
        $source = MigrationSource::create($data);
        ActivityLog::record('migration.source_added', $source->host);
        $this->connect($source);

        return redirect()->route('admin.migration.sources.show', $source);
    }

    public function testSource(MigrationSource $source): RedirectResponse
    {
        $error = $this->connect($source);

        return $error === null
            ? back()->with('status', __('Connected to :host.', ['host' => $source->host]))
            : back()->withErrors(['agent' => $error]);
    }

    public function forgetHostKey(MigrationSource $source): RedirectResponse
    {
        try {
            $this->agent->call('migrate.forget_host', $source->connection());
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        $source->update(['host_key' => null, 'status' => 'new']);
        ActivityLog::record('migration.host_key_reset', $source->host);

        return back()->with('status', __('The host key was removed. The next test records the new key.'));
    }

    public function destroySource(MigrationSource $source): RedirectResponse
    {
        if ($source->items()->whereIn('status', ['queued', ...MigrationItem::ACTIVE])->exists()) {
            return back()->withErrors(['agent' => __('Wait until the running migrations are finished.')]);
        }
        ActivityLog::record('migration.source_removed', $source->host);
        $source->delete();

        return redirect()->route('admin.migration.index')->with('status', __('Source server removed. Migrated websites stay.'));
    }

    public function inventory(MigrationSource $source): RedirectResponse
    {
        try {
            $job = $this->agent->call('migrate.inventory', $source->connection(), 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        $source->update(['inventory_job' => $job['job'] ?? null]);

        return back()->with('status', __('Reading the source server. This takes a minute.'));
    }

    public function showSource(Request $request, MigrationSource $source): View
    {
        $errors = [];
        $job = null;
        if ($source->inventory_job) {
            try {
                $job = $this->agent->call('job.status', ['job' => $source->inventory_job]);
                if (($job['status'] ?? '') === 'succeeded') {
                    $source->update(['inventory' => $job['result'], 'inventoried_at' => now(), 'inventory_job' => null, 'last_error' => null]);
                    $job = null;
                } elseif (($job['status'] ?? '') === 'failed') {
                    $source->update(['inventory_job' => null, 'last_error' => $job['error']['message'] ?? __('Reading the source server failed.')]);
                }
            } catch (AgentException $e) {
                $errors[] = $e->getMessage();
                if ($e->errorCode === 'not_found') {
                    $source->update(['inventory_job' => null]);
                }
            }
        }
        try {
            $this->migrator->tick();
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $items = $source->items()->with('customer')->get()->keyBy('domain');
        $search = trim((string) $request->query('q'));

        return view('migration::admin.source', [
            'source' => $source->fresh(), 'inventoryJob' => $job, 'items' => $items, 'agentErrors' => $errors,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'company']), 'search' => $search,
        ]);
    }

    public function migrate(Request $request, MigrationSource $source): RedirectResponse
    {
        $data = $request->validate([
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'max:253'],
            'customer' => ['array'],
            'customer.*' => ['nullable', 'string', 'regex:/^(new|\d+)$/'],
            'files' => ['boolean'], 'databases' => ['boolean'], 'mail' => ['boolean'], 'ssl' => ['boolean'],
        ], ['domains.required' => __('Select at least one domain.')]);

        $choices = [];
        foreach ($data['domains'] as $domain) {
            $c = $data['customer'][$this->key($domain)] ?? 'new';
            if ($c !== 'new' && ! Customer::whereKey((int) $c)->exists()) {
                $c = 'new';
            }
            $choices[$domain] = [
                'customer' => $c ?: 'new',
                'files' => $request->boolean('files'), 'databases' => $request->boolean('databases'),
                'mail' => $request->boolean('mail'), 'ssl' => $request->boolean('ssl'),
            ];
        }
        try {
            $items = $this->migrator->queue($source, $choices);
        } catch (Throwable $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice(':count domain is being migrated.|:count domains are being migrated.', count($items), ['count' => count($items)]));
    }

    public function syncAll(MigrationSource $source): RedirectResponse
    {
        $n = 0;
        foreach ($source->items()->where('status', 'copied')->get() as $item) {
            try {
                $this->migrator->startTransfer($item, 'sync');
                $n++;
            } catch (AgentException $e) {
                return back()->withErrors(['agent' => $e->getMessage()]);
            }
        }

        return back()->with('status', trans_choice(':count domain is being synced again.|:count domains are being synced again.', $n, ['count' => $n]));
    }

    public function showItem(MigrationItem $item): View
    {
        $errors = [];
        $job = null;
        try {
            $this->migrator->tick();
            $item->refresh();
            if ($item->agent_job && $item->isActive()) {
                $job = $this->agent->call('job.status', ['job' => $item->agent_job]);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return view('migration::admin.item', [
            'item' => $item->load('source', 'customer', 'website.databases'), 'job' => $job, 'agentErrors' => $errors,
            'log' => $item->logView($job),
            'secrets' => $item->secrets ?? [], 'serverIp' => $this->serverIp(),
        ]);
    }

    public function syncItem(Request $request, MigrationItem $item): RedirectResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(['sync', 'late_mail'])]])['kind'];
        $allowed = $kind === 'sync' ? $item->canSync() : in_array($item->status, ['live', 'done'], true);
        if (! $allowed) {
            return back()->withErrors(['agent' => __('This is not possible in the current state.')]);
        }
        try {
            $this->migrator->startTransfer($item, $kind);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', $kind === 'sync' ? __('Copying the changes since the last run.') : __('Fetching mail that still arrived on the old server.'));
    }

    public function retryItem(MigrationItem $item): RedirectResponse
    {
        if ($item->status !== 'failed') {
            return back();
        }
        if ($item->website?->status === 'active' && $item->copied_at !== null) {
            $this->migrator->startTransfer($item, 'sync');
        } else {
            $item->log('Tried again.');
            $item->update(['status' => 'queued', 'error' => null]);
            $this->migrator->tick();
        }

        return back()->with('status', __('Started again.'));
    }

    public function cutover(Request $request, MigrationItem $item, DnsCutover $cutover): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('Confirm that the copy was checked.')]);
        if ($item->status !== 'copied') {
            return back()->withErrors(['agent' => __('Copy the domain first; switch DNS when the copy is complete.')]);
        }
        try {
            $result = $cutover->run($item);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', $result['mode'] === 'cloudflare'
            ? __('DNS now points to this server (:count own records taken over).', ['count' => $result['imported']])
            : __('Load the zone file below at your DNS provider to finish the switch.'));
    }

    public function finishItem(MigrationItem $item): RedirectResponse
    {
        if (! in_array($item->status, ['live', 'copied'], true)) {
            return back();
        }
        $item->log('Marked as finished.', [], 'ok');
        $item->update(['status' => 'done', 'secrets' => null]);
        ActivityLog::record('migration.finished', $item->domain, customerId: $item->customer_id);

        return back()->with('status', __('Migration of :domain marked as finished.', ['domain' => $item->domain]));
    }

    private function serverIp(): ?string
    {
        try {
            return class_exists(\Rhis\DnsMail\Services\DnsMailConfig::class)
                ? app(\Rhis\DnsMail\Services\DnsMailConfig::class)->server()->ipv4 : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Form keys cannot contain dots. */
    public static function key(string $domain): string
    {
        return str_replace('.', '_', $domain);
    }

    /** Test the login; returns the error or null. */
    private function connect(MigrationSource $source): ?string
    {
        try {
            $info = $this->agent->call('migrate.connect', $source->connection(), 90);
        } catch (AgentException $e) {
            $source->update(['status' => 'error', 'last_error' => $e->getMessage()]);

            return $e->getMessage();
        }
        $source->update([
            'status' => 'connected', 'version' => $info['version'] ?? null, 'hostname' => $info['hostname'] ?? null,
            'host_key' => $info['host_key'] ?? $source->host_key, 'last_error' => $info['warning'] ?? null,
        ]);

        return null;
    }
}
