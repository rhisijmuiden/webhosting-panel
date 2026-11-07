<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use Rhis\WordPress\Models\WordPressInstall;

/**
 * One thing an admin can do on many WordPress sites at once (Bulk actions page).
 *
 * Packages add their own with BulkActions::register(); see docs/wordpress-bulk-actions.md.
 * Extend BaseAction instead of implementing this from scratch: it has sensible defaults.
 */
interface BulkAction
{
    /** Unique, stable key (kebab-case), stored with every run. */
    public function key(): string;

    /** Short name on the action tile, already translated. */
    public function label(): string;

    /** One sentence under the label, already translated. */
    public function description(): string;

    /** One of BulkActions::GROUPS (updates, security, plugins, maintenance), or your own. */
    public function group(): string;

    /** Panel icon name (x-icon). */
    public function icon(): string;

    /** Sort order inside the group (lower first). */
    public function order(): int;

    /** The options the admin fills in, built with Field::*. Their values arrive in $options. */
    public function fields(): array;

    /** Laravel validation rules for the options, keyed by field name. */
    public function rules(): array;

    /** Destructive or hard to undo: the admin must type a confirmation word. */
    public function dangerous(): bool;

    /** Run once per website instead of once per install (for website-wide work). */
    public function perWebsite(): bool;

    /** How many sites run at the same time by default. */
    public function concurrency(): int;

    /** Longest time one site may take, in seconds, before it is marked failed. */
    public function timeout(): int;

    /** Null when the action applies to this install, otherwise why it is skipped (translated). */
    public function skipReason(WordPressInstall $install, array $options): ?string;

    /** Do the work, or start an agent job and return Step::wait($jobId). */
    public function start(WordPressInstall $install, array $options): Step;

    /** Called when the agent job of Step::wait() has ended ($state is the job.status answer). */
    public function finish(WordPressInstall $install, array $options, array $state): Step;

    /** The chosen options in a few words, for the run history (translated). */
    public function describe(array $options): string;
}
