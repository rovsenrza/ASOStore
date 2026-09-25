<?php

namespace App\Console\Commands;

use App\Services\Inspection\IpaInspector;
use Illuminate\Console\Command;

/**
 * Runs the upload-time inspection (IMPLEMENTATION_PLAN §5.7) on a local IPA and
 * prints the report, without storing anything. For operators and fixtures.
 */
class InspectIpa extends Command
{
    protected $signature = 'ipa:inspect {path : Path to an .ipa file}';

    protected $description = 'Inspect an IPA like an upload would, and print the report';

    public function handle(IpaInspector $inspector): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $result = $inspector->inspect($path);
        $this->line((string) json_encode(['outcome' => $result->outcome->value, 'failure_code' => $result->failureCode] + $result->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result->passed() ? self::SUCCESS : self::FAILURE;
    }
}
