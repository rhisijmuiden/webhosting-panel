<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use App\Services\Agent\AgentClient;
use Rhis\WordPress\Models\WordPressInstall;

/** Defaults for a bulk action; override what you need. */
abstract class BaseAction implements BulkAction
{
    public function description(): string
    {
        return '';
    }

    public function icon(): string
    {
        return 'wordpress';
    }

    public function order(): int
    {
        return 50;
    }

    public function fields(): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function dangerous(): bool
    {
        return false;
    }

    public function perWebsite(): bool
    {
        return false;
    }

    public function concurrency(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 3600;
    }

    public function skipReason(WordPressInstall $install, array $options): ?string
    {
        return null;
    }

    /** Default: the agent job's result decides; override to store or explain more. */
    public function finish(WordPressInstall $install, array $options, array $state): Step
    {
        if (($state['status'] ?? '') === 'succeeded') {
            return Step::ok(null, $this->resultLines($state['result'] ?? []));
        }

        return Step::failed((string) ($state['error']['message'] ?? __('The job failed.')), array_slice((array) ($state['log'] ?? []), -5));
    }

    public function describe(array $options): string
    {
        return '';
    }

    protected function agent(): AgentClient
    {
        return app(AgentClient::class);
    }

    /** Start agent job $action for this install (domain + path are added) and wait for it. */
    protected function job(WordPressInstall $install, string $action, array $params = []): Step
    {
        $out = $this->agent()->call($action, $install->target() + $params, 30);

        return Step::wait((string) $out['job']);
    }

    /** Scalar values of an agent answer as label => value lines. */
    protected function resultLines(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        return collect($result)->filter(fn ($v) => is_scalar($v) || $v === null)
            ->except(['domain', 'path'])->map(fn ($v) => is_bool($v) ? ($v ? __('yes') : __('no')) : (string) $v)->take(12)->all();
    }
}
