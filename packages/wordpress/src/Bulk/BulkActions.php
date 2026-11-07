<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

use Closure;

/**
 * The registry of bulk actions. Any package can add one from its service provider:
 *
 *   BulkActions::register(MyAction::class);        // resolved from the container when needed
 *   BulkActions::register(new CallbackAction(...)); // or an instance
 *
 * The same key registered twice replaces the earlier one.
 */
final class BulkActions
{
    /** Built-in groups, in the order they are shown. Unknown groups come after them. */
    public const GROUPS = ['updates', 'security', 'plugins', 'maintenance'];

    /** @var array<string, BulkAction|class-string<BulkAction>|Closure> */
    private static array $pending = [];

    /** @var array<string, BulkAction>|null */
    private static ?array $resolved = null;

    public static function register(BulkAction|string|Closure $action): void
    {
        if ($action instanceof BulkAction) {
            self::$pending[$action->key()] = $action;
        } else {
            self::$pending[is_string($action) ? $action : spl_object_hash($action)] = $action;
        }
        self::$resolved = null;
    }

    /** Remove an action (for example one a newer package replaces under another key). */
    public static function forget(string $key): void
    {
        self::all();
        unset(self::$resolved[$key]);
        self::$pending = self::$resolved;
    }

    public static function reset(): void
    {
        self::$pending = [];
        self::$resolved = null;
    }

    /** @return array<string, BulkAction> keyed by action key, ordered by group and order */
    public static function all(): array
    {
        if (self::$resolved === null) {
            $list = [];
            foreach (self::$pending as $entry) {
                $action = match (true) {
                    $entry instanceof BulkAction => $entry,
                    $entry instanceof Closure => $entry(),
                    default => app($entry),
                };
                $list[$action->key()] = $action;
            }
            uasort($list, fn (BulkAction $a, BulkAction $b) => [self::groupIndex($a->group()), $a->order(), $a->key()]
                <=> [self::groupIndex($b->group()), $b->order(), $b->key()]);
            self::$resolved = $list;
        }

        return self::$resolved;
    }

    public static function find(?string $key): ?BulkAction
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /** @return array<string, array<string, BulkAction>> group => actions */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::all() as $key => $action) {
            $out[$action->group()][$key] = $action;
        }

        return $out;
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'updates' => __('Updates'),
            'security' => __('Security'),
            'plugins' => __('Plugins and themes'),
            'maintenance' => __('Maintenance'),
            default => __(ucfirst(str_replace(['-', '_'], ' ', $group))),
        };
    }

    private static function groupIndex(string $group): int
    {
        $i = array_search($group, self::GROUPS, true);

        return $i === false ? 100 : $i;
    }
}
