<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use Closure;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * A bulk action made from closures, for packages that only need a few lines:
 *
 *   BulkActions::register(new CallbackAction(
 *       key: 'maintenance-on', label: fn () => __('Maintenance mode on'), group: 'maintenance',
 *       run: fn (WordPressInstall $i, array $o) => app(Toggles::class)->maintenance($i, true) ? Step::ok() : Step::failed('...'),
 *   ));
 *
 * Labels may be closures so they are translated in the viewer's language.
 */
final class CallbackAction extends BaseAction
{
    public function __construct(
        private readonly string $key,
        private readonly string|Closure $label,
        private readonly string $group,
        private readonly Closure $run,
        private readonly string|Closure $description = '',
        private readonly string $icon = 'wordpress',
        private readonly int $order = 50,
        private readonly array|Closure $fields = [],
        private readonly array $rules = [],
        private readonly bool $dangerous = false,
        private readonly bool $perWebsite = false,
        private readonly int $concurrency = 3,
        private readonly ?Closure $skip = null,
        private readonly ?Closure $finish = null,
        private readonly ?Closure $describe = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return (string) value($this->label);
    }

    public function description(): string
    {
        return (string) value($this->description);
    }

    public function group(): string
    {
        return $this->group;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function fields(): array
    {
        return (array) value($this->fields);
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function dangerous(): bool
    {
        return $this->dangerous;
    }

    public function perWebsite(): bool
    {
        return $this->perWebsite;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function skipReason(WordPressInstall $install, array $options): ?string
    {
        return $this->skip ? ($this->skip)($install, $options) : null;
    }

    public function start(WordPressInstall $install, array $options): Step
    {
        $out = ($this->run)($install, $options);
        if ($out instanceof Step) {
            return $out;
        }

        // An agent answer: a job to wait for, or a finished result.
        return is_array($out) && isset($out['job']) ? Step::wait((string) $out['job']) : Step::ok(null, $this->resultLines($out));
    }

    public function finish(WordPressInstall $install, array $options, array $state): Step
    {
        return $this->finish ? ($this->finish)($install, $options, $state) : parent::finish($install, $options, $state);
    }

    public function describe(array $options): string
    {
        return $this->describe ? (string) ($this->describe)($options) : '';
    }
}
