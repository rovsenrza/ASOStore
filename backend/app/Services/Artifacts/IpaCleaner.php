<?php

namespace App\Services\Artifacts;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The PHP side of tools/ipa-cleaner. analyze() never fails an inspection: without the
 * tool the report simply has no cleaning section. clean() throws CleaningRefused with the
 * tool's own explanation when a removal would not be safe.
 */
class IpaCleaner
{
    public function enabled(): bool
    {
        return (bool) config('storefront.ipa_cleaner.enabled', true) && is_file((string) config('storefront.ipa_cleaner.script'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analyze(string $path): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            return $this->run(['analyze', $path]);
        } catch (CleaningRefused $refused) {
            return ['error' => $refused->getMessage()];
        } catch (Throwable $exception) {
            // A missing interpreter or a crash must not fail the inspection itself.
            Log::warning('ipa_cleaner.analyze_failed', ['error' => $exception->getMessage()]);

            return ['error' => 'Анализ не выполнен: '.mb_substr($exception->getMessage(), 0, 300)];
        }
    }

    /**
     * @param  array{remove?: list<string>, remove_extensions?: list<string>, opt_in?: list<string>, recommended?: bool, fix_metadata?: bool}  $selection
     * @return array<string, mixed> The tool's report: hashes, what went, verification.
     */
    public function clean(string $source, string $output, array $selection): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The IPA cleaner is not installed.');
        }
        $arguments = ['clean', $source, $output];
        foreach ($selection['remove'] ?? [] as $path) {
            array_push($arguments, '--remove', $path);
        }
        foreach ($selection['remove_extensions'] ?? [] as $path) {
            array_push($arguments, '--remove-extension', $path);
        }
        foreach ($selection['opt_in'] ?? [] as $rule) {
            array_push($arguments, '--opt-in', $rule);
        }
        if ($selection['recommended'] ?? false) {
            $arguments[] = '--recommended';
        }
        if ($selection['fix_metadata'] ?? false) {
            $arguments[] = '--fix-metadata';
        }

        return $this->run($arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function run(array $arguments): array
    {
        $result = Process::timeout((int) config('storefront.ipa_cleaner.timeout', 900))
            ->run([(string) config('storefront.ipa_cleaner.python', 'python3'), (string) config('storefront.ipa_cleaner.script'), ...$arguments]);
        $output = json_decode(trim($result->output()), true);
        if ($result->exitCode() === 2 && is_array($output) && isset($output['error']['message'])) {
            throw new CleaningRefused((string) $output['error']['message']);
        }
        if (! $result->successful() || ! is_array($output)) {
            throw new RuntimeException(mb_substr(trim($result->errorOutput()) ?: 'exit code '.$result->exitCode(), -500));
        }

        return $output;
    }
}
