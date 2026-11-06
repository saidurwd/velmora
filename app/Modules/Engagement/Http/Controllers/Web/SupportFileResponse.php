<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** How a file sent with a ticket is handed out: a picture (already re-encoded by us) may be shown, a PDF is only ever downloaded; the browser is told not to guess the type and not to keep a copy. */
final class SupportFileResponse
{
    /** @param  array{key: string, mime: string, name: string}  $file */
    public static function stream(array $file): StreamedResponse
    {
        $disk = Storage::disk((string) config('engagement.support.disk', 'private'));
        abort_unless($disk->exists($file['key']), 404);
        $inline = $file['mime'] === 'image/webp';
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file['name']) ?: 'file';

        return response()->stream(function () use ($disk, $file): void {
            $stream = $disk->readStream($file['key']);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file['mime'], 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
