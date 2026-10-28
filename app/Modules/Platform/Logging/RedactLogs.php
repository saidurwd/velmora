<?php

declare(strict_types=1);

namespace App\Modules\Platform\Logging;

use App\Modules\Platform\Support\Redactor;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/** Log channel tap: JSON lines with secrets/PII redacted from message and context (REQ-064). */
final class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: Redactor::string($record->message),
                context: Redactor::array($record->context),
                extra: Redactor::array($record->extra),
            );
        });

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
            }
        }
    }
}
