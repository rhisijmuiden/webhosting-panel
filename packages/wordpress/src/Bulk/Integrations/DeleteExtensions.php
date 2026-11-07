<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Integrations;

/** Delete named plugins/themes (inactive only; the agent refuses active ones). */
final class DeleteExtensions extends ManageExtensions
{
    public function key(): string
    {
        return 'plugins-delete';
    }

    public function label(): string
    {
        return __('Delete plugins or themes');
    }

    public function description(): string
    {
        return __('Remove named plugins or themes where they are inactive. Active ones are left alone and reported.');
    }

    public function icon(): string
    {
        return 'trash';
    }

    public function order(): int
    {
        return 30;
    }

    public function dangerous(): bool
    {
        return true;
    }

    protected function ops(): array
    {
        return ['delete' => __('Delete')];
    }
}
