<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\ImageReencoder;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files sent with a ticket message. Checked by what the bytes really are, never by the name or the type the browser claims: a picture is decoded and re-encoded to WebP
 * (which drops camera and location data and anything hidden after the picture); a PDF must really start as one and is only ever downloaded. Everything else is refused.
 * Files are kept on the private disk under random keys and the virus-scan hook is asked first. Limits: a few files per message, a few megabytes each.
 */
final class SupportUploads
{
    public function __construct(private readonly ImageReencoder $images, private readonly MalwareScanner $scanner, private readonly Clock $clock) {}

    /**
     * Checks every file and returns what to keep; nothing is written yet.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, mime: string, bytes: string}>
     *
     * @throws EngagementException too_many_files | file_too_large | file_type | file_unsafe | file_unreadable
     */
    public function prepare(array $files): array
    {
        $max = (int) config('engagement.support.attachments_per_message', 3);
        if (count($files) > $max) {
            throw new EngagementException('too_many_files', ['max' => $max]);
        }

        $out = [];
        foreach ($files as $file) {
            $limit = (int) config('engagement.support.attachment_max_bytes', 5_242_880);
            $path = $file->getRealPath();
            $bytes = $path === false ? false : file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                throw new EngagementException('file_unreadable');
            }
            if (strlen($bytes) > $limit) {
                throw new EngagementException('file_too_large', ['mb' => (int) ($limit / 1_048_576)]);
            }
            if (! $this->scanner->isClean($bytes, $file->getClientOriginalName())) {
                throw new EngagementException('file_unsafe');
            }

            $name = $this->cleanName($file->getClientOriginalName());
            if (str_starts_with($bytes, '%PDF-') && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'application/pdf') {
                $out[] = ['name' => $name, 'mime' => 'application/pdf', 'bytes' => $bytes];

                continue;
            }
            try {
                $image = $this->images->webp($bytes, [1600], 1);
            } catch (DomainException) {
                throw new EngagementException('file_type');
            }
            $out[] = ['name' => pathinfo($name, PATHINFO_FILENAME).'.webp', 'mime' => 'image/webp', 'bytes' => (string) reset($image['files'])];
        }

        return $out;
    }

    /**
     * Writes the prepared files and their rows. If anything fails the files already written are removed before the error goes on.
     *
     * @param  list<array{name: string, mime: string, bytes: string}>  $prepared
     */
    public function store(array $prepared, int $messageId): void
    {
        $disk = Storage::disk((string) config('engagement.support.disk', 'private'));
        $written = [];
        try {
            foreach ($prepared as $file) {
                $key = 'support/'.strtolower((string) Str::ulid()).($file['mime'] === 'application/pdf' ? '.pdf' : '.webp');
                $disk->put($key, $file['bytes']);
                $written[] = $key;
                DB::table('support_attachments')->insert(['public_id' => strtolower((string) Str::ulid()), 'message_id' => $messageId, 'original_name' => $file['name'], 'mime' => $file['mime'], 'size_bytes' => strlen($file['bytes']), 'storage_key' => $key, 'created_at' => $this->clock->now()]);
            }
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }
    }

    /** Takes the file's own name apart from any path or odd characters, and keeps it short. */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\\\\\/"<>|:*?]/u', '', basename(str_replace('\\', '/', $name))));
        $name = mb_substr($name, 0, 90);

        return $name === '' ? 'file' : $name;
    }
}
