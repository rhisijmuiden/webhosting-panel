<?php

declare(strict_types=1);

namespace Rhis\DnsMail\MailExtras;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\DnsMail\Models\MailImport;
use Throwable;

/**
 * Starts, follows and stops IMAP imports on the server (agent mail.imap_import_*).
 * The server runs a few at a time; the rest wait as "queued" and the
 * dns-mail:imports command starts them when a slot is free.
 */
final class ImportRunner
{
    public function __construct(private readonly AgentClient $agent)
    {
    }

    /** Test the source login; returns the agent's folder and message count. */
    public function test(array $source): array
    {
        return $this->agent->call('mail.imap_import_test', $this->sourceParams($source), 90);
    }

    public function available(): bool
    {
        try {
            return (bool) ($this->agent->call('mail.imap_import_available', [], 15)['available'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Try to start a queued import. Returns false when the server is busy (stays queued). */
    public function start(MailImport $import): bool
    {
        $import->loadMissing('mailbox.domain');
        if ($import->password === null) {
            $import->update(['status' => 'failed', 'error' => __('Enter the password again to run this import.')]);

            return false;
        }
        try {
            $this->agent->call('mail.imap_import_start', [
                'id' => $import->id,
                'email' => $import->mailbox->address(),
                ...$this->sourceParams($import->only(['host', 'port', 'security', 'username', 'password'])),
            ], 60);
        } catch (AgentException $e) {
            if (($e->details['busy'] ?? false) === true) {
                $import->update(['status' => 'queued', 'error' => null]);

                return false;
            }
            $import->update(['status' => 'failed', 'error' => $e->getMessage(), 'password' => null, 'finished_at' => now()]);
            $this->log($import, 'mail.import_failed');

            return false;
        }
        $import->update([
            'status' => 'running', 'error' => null, 'started_at' => now(), 'finished_at' => null, 'checked_at' => now(),
            'done' => 0, 'percent' => 0, 'total' => null, 'transferred' => null, 'skipped' => null, 'errors' => null, 'bytes' => null,
            'log' => [], 'runs' => $import->runs + 1,
        ]);
        $this->log($import, 'mail.import_started');

        return true;
    }

    /** Pull the progress of a running import from the server. */
    public function refresh(MailImport $import): MailImport
    {
        if ($import->status !== 'running') {
            return $import;
        }
        try {
            $status = $this->agent->call('mail.imap_import_status', ['id' => $import->id], 30);
        } catch (Throwable $e) {
            report($e);

            return $import;
        }
        $p = $status['progress'] ?? [];
        $state = $status['state'] ?? 'unknown';
        $fields = [
            'total' => $p['total'] ?? $import->total,
            'done' => (int) ($p['done'] ?? $import->done),
            'percent' => (int) ($p['percent'] ?? $import->percent),
            'transferred' => $p['transferred'] ?? null,
            'skipped' => $p['skipped'] ?? null,
            'errors' => $p['errors'] ?? null,
            'bytes' => $p['bytes'] ?? null,
            'log' => array_slice((array) ($status['log'] ?? []), -40),
            'checked_at' => now(),
        ];
        if ($state !== 'running') {
            $fields += [
                'status' => in_array($state, ['done', 'partial', 'failed', 'stopped'], true) ? $state : 'failed',
                'password' => null,
                'finished_at' => now(),
                'error' => $state === 'done' ? null : (implode("\n", array_slice((array) ($status['error_lines'] ?? []), -5)) ?: ($state === 'unknown' ? __('The server lost track of this import.') : null)),
            ];
            if ($state === 'done') {
                $fields['percent'] = 100;
            }
        }
        $import->update($fields);
        if ($state !== 'running') {
            $this->log($import, 'mail.import_' . $import->status);
        }

        return $import;
    }

    public function stop(MailImport $import): void
    {
        if ($import->status === 'running') {
            try {
                $this->agent->call('mail.imap_import_stop', ['id' => $import->id], 60);
            } catch (AgentException $e) {
                if ($e->errorCode !== 'not_found') {
                    throw $e;
                }
            }
        }
        $import->update(['status' => 'stopped', 'password' => null, 'finished_at' => now()]);
        $this->log($import, 'mail.import_stopped');
    }

    public function forget(MailImport $import): void
    {
        if ($import->status === 'running') {
            $this->stop($import);
        }
        try {
            $this->agent->call('mail.imap_import_forget', ['id' => $import->id], 30);
        } catch (Throwable) {
            // Nothing on the server (never started); removing the row is enough.
        }
        $import->delete();
    }

    /** Follow running imports and start waiting ones (every minute). */
    public function tick(): array
    {
        $refreshed = 0;
        foreach (MailImport::where('status', 'running')->get() as $import) {
            $this->refresh($import);
            $refreshed++;
        }
        $started = 0;
        foreach (MailImport::where('status', 'queued')->orderBy('id')->limit(20)->get() as $import) {
            if (!$this->start($import) && $import->fresh()?->status === 'queued') {
                break; // server busy: try again next minute
            }
            $started += $import->fresh()?->status === 'running' ? 1 : 0;
        }

        return ['refreshed' => $refreshed, 'started' => $started];
    }

    private function sourceParams(array $s): array
    {
        return [
            'host' => (string) $s['host'],
            'port' => (int) $s['port'],
            'security' => (string) $s['security'],
            'user' => (string) ($s['username'] ?? $s['user'] ?? ''),
            'password' => (string) $s['password'],
        ];
    }

    private function log(MailImport $import, string $action): void
    {
        $import->loadMissing('mailbox.domain');
        ActivityLog::record($action, $import->source() . ' → ' . $import->mailbox?->address(), ['id' => $import->id], $import->mailbox?->domain?->customer_id);
    }
}
