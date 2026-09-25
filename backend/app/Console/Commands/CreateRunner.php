<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Registers a macOS signing runner and prints its worker API key once
 * (IMPLEMENTATION_PLAN P6-RUN-01, D9).
 */
class CreateRunner extends Command
{
    protected $signature = 'runner:create {name : A label, e.g. "mac-mini-1"}';

    protected $description = 'Register a signing runner and print its key ID and secret once';

    public function handle(AuditService $audit): int
    {
        $secret = bin2hex(random_bytes(32));
        $runner = Runner::create([
            'key_id' => 'rk_'.Str::lower(Str::random(20)),
            'name' => (string) $this->argument('name'),
            'secret_encrypted' => $secret,
        ]);
        $audit->record('runner.created', $runner, after: ['key_id' => $runner->key_id, 'name' => $runner->name], actor: Actor::system('console'));

        $this->line('Put these in the runner configuration (runner/README.md). The secret is not shown again.');
        $this->line("STOREFRONT_RUNNER_KEY_ID={$runner->key_id}");
        $this->line("STOREFRONT_RUNNER_SECRET={$secret}");

        return self::SUCCESS;
    }
}
