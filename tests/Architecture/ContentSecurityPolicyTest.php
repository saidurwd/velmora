<?php

declare(strict_types=1);

/*
 * Phase 12: the Content-Security-Policy is ENFORCED (script-src 'self'). That only works if no page depends on inline JavaScript, so this fails the build when a view
 * brings one back: an inline event handler (onclick="...", onsubmit="...", javascript: links) or an inline <script> that is not data (JSON-LD). Use a data attribute and
 * resources/js/behaviors.js instead.
 */

it('has no inline event handlers in any view', function () {
    $found = [];
    foreach (bladeViews() as $file) {
        foreach (file($file) as $n => $line) {
            if (preg_match('/\s(on[a-z]{3,})\s*=\s*["\']|href\s*=\s*["\']\s*javascript:/i', $line, $m) && ! str_contains($line, 'data-on')) {
                $found[] = str_replace(base_path().'/', '', $file).':'.($n + 1).' '.trim($m[0]);
            }
        }
    }

    expect($found)->toBe([]);
});

it('has no inline script in any view except data blocks', function () {
    $found = [];
    foreach (bladeViews() as $file) {
        preg_match_all('/<script\b([^>]*)>/i', (string) file_get_contents($file), $tags);
        foreach ($tags[1] as $attrs) {
            if (! preg_match('/type\s*=\s*["\']application\/ld\+json["\']/i', $attrs) && ! preg_match('/\bsrc\s*=/i', $attrs)) {
                $found[] = str_replace(base_path().'/', '', $file).' <script'.$attrs.'>';
            }
        }
    }

    expect($found)->toBe([]);
});

it('enforces the policy by default, never allows inline script, and lets the payment gateway be reached', function () {
    $csp = config('security.csp');
    $directives = collect(explode(';', (string) $csp['policy']))->mapWithKeys(fn (string $d) => [explode(' ', trim($d))[0] => trim($d)]);

    expect($csp['enabled'])->toBeTrue()->and($csp['enforce'])->toBeTrue()
        ->and($directives['script-src'])->not->toContain("'unsafe-inline'")->not->toContain("'unsafe-eval'")->and($directives['script-src'])->toContain("'self'")
        ->and($directives['default-src'])->toBe("default-src 'self'")->and($directives['object-src'])->toBe("object-src 'none'")
        ->and($directives['base-uri'])->toBe("base-uri 'self'")->and($directives['frame-ancestors'])->toBe("frame-ancestors 'self'")
        ->and($directives['form-action'])->toContain("'self'")->toContain('https://securepay.sslcommerz.com')->toContain('https://sandbox.sslcommerz.com');
});

it('is not switched off by the example environment file that new servers are built from', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('CSP_ENFORCE=true')->not->toMatch('/^CSP_ENFORCE=false/m')->not->toMatch('/^CSP_ENABLED=false/m');
});
