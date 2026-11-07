<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

/** Option fields for a bulk action. Labels and help texts are translated by the caller. */
final class Field
{
    public static function checkbox(string $name, string $label, bool $default = false, ?string $help = null): array
    {
        return ['type' => 'checkbox', 'name' => $name, 'label' => $label, 'default' => $default, 'help' => $help];
    }

    /** $options: value => label. */
    public static function select(string $name, string $label, array $options, string|int|null $default = null, ?string $help = null): array
    {
        return ['type' => 'select', 'name' => $name, 'label' => $label, 'options' => $options,
            'default' => $default ?? array_key_first($options), 'help' => $help];
    }

    public static function text(string $name, string $label, ?string $placeholder = null, ?string $help = null, string $default = ''): array
    {
        return ['type' => 'text', 'name' => $name, 'label' => $label, 'placeholder' => $placeholder, 'default' => $default, 'help' => $help];
    }

    public static function number(string $name, string $label, int $default, int $min, int $max, ?string $help = null): array
    {
        return ['type' => 'number', 'name' => $name, 'label' => $label, 'default' => $default, 'min' => $min, 'max' => $max, 'help' => $help];
    }

    /** A note in the options panel, no input. */
    public static function note(string $text): array
    {
        return ['type' => 'note', 'name' => '', 'label' => $text, 'default' => null, 'help' => null];
    }
}
