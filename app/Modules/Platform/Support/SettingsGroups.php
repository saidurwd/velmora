<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * The editable groups of settings and their fields: the setting key, the kind of value and its limit. One list, so the screen, the check
 * and the audit line can never disagree.
 */
final class SettingsGroups
{
    public const GROUPS = ['business', 'tax'];

    /**
     * Field name (the form field) => key, type (text | email | digits | phone | bool) and the longest allowed text.
     *
     * @return array<string, array{key: string, type: string, max: int}>
     */
    public static function fields(string $group): array
    {
        $fields = [];
        if ($group === 'business') {
            foreach ((array) config('app.supported_locales') as $locale) {
                $fields["legal_name_{$locale}"] = ['key' => "business.legal_name.{$locale}", 'type' => 'text', 'max' => 120];
                $fields["address_{$locale}"] = ['key' => "business.address.{$locale}", 'type' => 'text', 'max' => 400];
                $fields["invoice_footer_{$locale}"] = ['key' => "business.invoice_footer.{$locale}", 'type' => 'text', 'max' => 300];
            }
            $fields['phone'] = ['key' => 'business.phone', 'type' => 'phone', 'max' => 40];
            $fields['email'] = ['key' => 'business.email', 'type' => 'email', 'max' => 120];
            $fields['bin'] = ['key' => 'business.bin', 'type' => 'digits', 'max' => 20];
            $fields['tin'] = ['key' => 'business.tin', 'type' => 'digits', 'max' => 20];
        } elseif ($group === 'tax') {
            foreach (['enabled', 'prices_include_tax', 'shipping_taxable', 'cod_fee_taxable'] as $name) {
                $fields[$name] = ['key' => "tax.{$name}", 'type' => 'bool', 'max' => 0];
            }
        }

        return $fields;
    }

    /** @return list<string> */
    public static function keys(string $group): array
    {
        return array_values(array_map(fn (array $f) => $f['key'], self::fields($group)));
    }
}
