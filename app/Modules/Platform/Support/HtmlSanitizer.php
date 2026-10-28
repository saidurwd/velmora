<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Allow-list sanitiser for staff-written rich text (product descriptions, CMS). Stored XSS is stopped here, at write
 * time, so every reader (web, API, email) gets safe HTML. No scripts, styles, event handlers, iframes or inline images.
 */
final class HtmlSanitizer
{
    private ?HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        return trim($this->purifier()->purify(BanglaText::nfc($html)));
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $cache = storage_path('framework/cache/purifier');
        if (! is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('Cache.SerializerPath', $cache);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,ul,ol,li,h2,h3,h4,blockquote,hr,table,thead,tbody,tr,th[scope],td[colspan|rowspan],a[href|title],span[lang]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('URI.DisableExternalResources', true);
        $config->set('HTML.Nofollow', true);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.AutoParagraph', false);

        return $this->purifier = new HTMLPurifier($config);
    }
}
