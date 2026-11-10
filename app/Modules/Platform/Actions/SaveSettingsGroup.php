<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\SettingsGroups;
use App\Modules\Platform\Support\SettingsWriter;

/** Saves one group of settings (business profile, tax switches): each field checked for its kind, written together, audited with before and after. */
final class SaveSettingsGroup
{
    public function __construct(private readonly SettingsWriter $writer) {}

    /**
     * @param  array<string, mixed>  $input  form field => value
     *
     * @throws SettingsException
     */
    public function handle(Actor $actor, string $group, array $input, string $version): void
    {
        in_array($group, SettingsGroups::GROUPS, true) || throw SettingsException::unknown();

        $values = [];
        foreach (SettingsGroups::fields($group) as $name => $def) {
            $values[$def['key']] = $this->clean($name, $def['type'], $def['max'], $input[$name] ?? null);
        }

        $this->writer->save($actor, "settings.{$group}_updated", $values, $version);
    }

    private function clean(string $field, string $type, int $max, mixed $raw): bool|string
    {
        if ($type === 'bool') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        $text = trim(BanglaText::input(is_scalar($raw) ? (string) $raw : ''));
        if (mb_strlen($text) > $max) {
            throw SettingsException::invalid($field, 'too_long');
        }
        if ($text === '') {
            return '';
        }

        return match ($type) {
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($text) : throw SettingsException::invalid($field),
            'digits' => preg_match('/^[0-9][0-9\-]*$/', $text) === 1 ? $text : throw SettingsException::invalid($field),   // a BIN or TIN: digits, with hyphens
            'phone' => preg_match('/^\+?[0-9][0-9\s\-]{5,}$/', $text) === 1 ? $text : throw SettingsException::invalid($field),
            default => $text,
        };
    }
}
