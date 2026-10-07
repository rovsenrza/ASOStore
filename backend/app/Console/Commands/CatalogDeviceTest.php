<?php

namespace App\Console\Commands;

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\SignedBuild;
use App\Models\User;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Artifacts\CleanedCopyBuilder;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Installations\InstallationService;
use App\Services\Signing\KeptBundleIds;
use App\Services\Signing\SigningService;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use App\StateMachines\StateMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Server side of the device-test pipeline (tools/device-test): a Mac with an iPhone on USB
 * installs each catalog build, watches it run and decides; everything that touches the
 * catalog happens here, through the same services as the admin panel. One action per call,
 * one JSON object on stdout ({"ok": false, "error": …} on failure, exit status 1).
 *
 *   queue --category=…    published listings to test, smallest build first
 *   sign ARTIFACT         a build of the artifact the test device can install
 *   build BUILD           its state, and a download link once it is deliverable
 *   candidate APP         a cleaned, approved copy of the live build (not published)
 *   publish ARTIFACT      that copy replaces the live build (it passed on the device)
 *   discard ARTIFACT      that copy failed on the device and is withdrawn
 *   keep APP [--off]      the app keeps (or stops keeping) its original bundle ID
 *   notify --message=…    a message to the Telegram admins
 */
class CatalogDeviceTest extends Command
{
    protected $signature = 'catalog:device-test
        {action : queue|sign|build|candidate|publish|discard|keep|notify}
        {target? : Artifact, build or app ID, by action}
        {--category=* : queue: category slugs, e.g. games-arcade}
        {--device=1 : Device the builds are signed for}
        {--user=1 : Staff user who approves and publishes}
        {--off : keep: stop keeping the original bundle ID}
        {--message= : notify: the text}
        {--wait=1800 : candidate: seconds to wait for cleaning and inspection}';

    protected $description = 'Catalog steps of the USB device-test pipeline (JSON in, JSON out)';

    public function handle(): int
    {
        try {
            $result = match ($this->argument('action')) {
                'queue' => $this->queue(),
                'sign' => $this->sign(),
                'build' => $this->build(),
                'candidate' => $this->candidate(),
                'publish' => $this->publish(),
                'discard' => $this->discard(),
                'keep' => $this->keep(),
                'notify' => $this->notify(),
                default => throw new RuntimeException('Unknown action'),
            };
            $this->line(json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->line(json_encode(['ok' => false, 'error' => mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $categories = (array) $this->option('category');
        if ($categories === []) {
            throw new RuntimeException('Give at least one --category');
        }
        $kept = app(KeptBundleIds::class);
        $apps = AppArtifact::query()->where('status', ArtifactStatus::Published->value)->whereNull('purged_at')
            ->whereHas('app', fn ($app) => $app->where('visibility', AppVisibility::Published->value)
                ->whereHas('category', fn ($category) => $category->whereIn('slug', $categories)))
            ->with('app')->orderBy('size_bytes')->get()
            ->map(fn (AppArtifact $artifact) => [
                'app_id' => $artifact->app_id,
                'name' => $artifact->app->name,
                'artifact_id' => $artifact->id,
                'sha256' => $artifact->sha256,
                'size_bytes' => (int) $artifact->size_bytes,
                'version' => $artifact->version,
                'bundle_identifier' => $artifact->bundle_identifier,
                'cleaned_copy' => $artifact->derived_from_artifact_id !== null,
                'kept_bundle_id' => is_string($artifact->bundle_identifier) && $kept->matches($artifact->bundle_identifier),
            ])->values()->all();

        return ['apps' => $apps];
    }

    /** @return array<string, mixed> */
    private function sign(): array
    {
        $artifact = AppArtifact::query()->findOrFail((int) $this->argument('target'));
        if (! in_array($artifact->status, [ArtifactStatus::Published, ArtifactStatus::Ready], true)) {
            throw new RuntimeException('Artifact is '.$artifact->status->value.', not PUBLISHED or READY');
        }
        // A test build is background work: it waits for an idle runner like pre-signing does.
        $build = app(SigningService::class)->requestBuild($artifact, $this->device(), priority: 10);

        return $this->describe($build->refresh());
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $build = SignedBuild::query()->findOrFail((int) $this->argument('target'));
        $result = $this->describe($build);
        if ($build->status === SignedBuildStatus::Deliverable && $build->storage_path !== null && $build->purged_at === null) {
            // Downloading counts as use: the storage janitor leaves the file alone meanwhile.
            $build->forceFill(['last_used_at' => now()])->save();
            $result['url'] = Storage::disk('artifacts')->temporaryUrl($build->storage_path, now()->addHours(3));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        $app = CatalogApp::query()->findOrFail((int) $this->argument('target'));
        $live = $app->publishedArtifact()->first() ?? throw new RuntimeException('Listing has no published build');
        $builder = app(CleanedCopyBuilder::class);
        if (! $builder->available()) {
            throw new RuntimeException('The IPA cleaner is not installed on this server');
        }
        $prepared = $builder->prepare($live, $this->user(), 'Device test', (int) $this->option('wait'));
        $copy = $prepared['copy'];

        return [
            'result' => $prepared['result'],
            'detail' => $prepared['detail'],
            'removed' => $prepared['removed'],
            'live_artifact_id' => $live->id,
            'artifact_id' => $copy?->id,
            'status' => $copy?->status->value,
            'size_bytes' => $copy ? (int) $copy->size_bytes : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publish(): array
    {
        $copy = $this->copy([ArtifactStatus::Ready]);
        $live = CatalogApp::query()->findOrFail($copy->app_id)->publishedArtifact()->first();
        // Only the build the copy was made from may be replaced: anything else is a newer upload.
        if ($live === null || $live->id !== $copy->derived_from_artifact_id) {
            throw new RuntimeException('The live build is no longer the one this copy was made from');
        }
        app(ArtifactReviewService::class)->publish($copy, $this->user());
        app(AuditService::class)->record('catalog.device_test_replaced', $copy, after: [
            'source_artifact_id' => $live->public_id,
            'device_id' => (int) $this->option('device'),
        ], actor: Actor::user($this->user()));

        return ['artifact_id' => $copy->id, 'status' => $copy->refresh()->status->value, 'superseded_artifact_id' => $live->id];
    }

    /** @return array<string, mixed> */
    private function discard(): array
    {
        $copy = $this->copy([ArtifactStatus::Ready, ArtifactStatus::ProvenanceReview]);
        $review = app(ArtifactReviewService::class);
        $reason = 'Did not run on the test device';
        $copy->status === ArtifactStatus::Ready
            ? $review->revoke($copy, $this->user(), $reason)
            : $review->reject($copy, $this->user(), $reason);

        return ['artifact_id' => $copy->id, 'status' => $copy->refresh()->status->value];
    }

    /**
     * Keeping the original bundle ID changes how the app is signed, so every deliverable
     * build of its live build and pending copy is retired: the next install signs again.
     *
     * @return array<string, mixed>
     */
    private function keep(): array
    {
        $app = CatalogApp::query()->findOrFail((int) $this->argument('target'));
        $live = $app->publishedArtifact()->first() ?? throw new RuntimeException('Listing has no published build');
        $artifacts = collect([$live, app(CleanedCopyBuilder::class)->pendingCopy($live)])->filter();
        $kept = app(KeptBundleIds::class);
        $ids = $artifacts->pluck('bundle_identifier')->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values();
        foreach ($ids as $id) {
            $this->option('off') ? $kept->remove($id) : $kept->add($id);
        }
        $retired = $this->retire(
            SignedBuild::query()->whereIn('artifact_id', $artifacts->pluck('id'))->where('status', SignedBuildStatus::Deliverable->value)->get(),
            'KEEP_BUNDLE_ID_CHANGED',
        );
        app(AuditService::class)->record($this->option('off') ? 'signing.keep_bundle_id_removed' : 'signing.keep_bundle_id_added', $app, after: [
            'bundle_identifiers' => $ids->all(), 'retired_builds' => $retired, 'source' => 'device-test',
        ], actor: Actor::user($this->user()));

        return [
            'bundle_identifiers' => $ids->all(),
            'kept' => $ids->every(fn (string $id) => $kept->matches($id)),
            'retired_builds' => $retired,
        ];
    }

    /** @return array<string, mixed> */
    private function notify(): array
    {
        $message = trim((string) $this->option('message'));
        if ($message === '') {
            throw new RuntimeException('Empty --message');
        }
        app(Messenger::class)->notifyAdmins(new Screen('🧪 '.e($message)));

        return [];
    }

    /** @return array<string, mixed> */
    private function describe(SignedBuild $build): array
    {
        $artifact = AppArtifact::query()->findOrFail($build->artifact_id);
        $team = $build->apple_team_id !== null ? AppleTeam::query()->find($build->apple_team_id) : $this->device()->latestRegistration?->team;

        return [
            'build_id' => $build->id,
            'artifact_id' => $artifact->id,
            'status' => $build->status->value,
            'status_reason' => $build->status_reason,
            'size_bytes' => $build->size_bytes !== null ? (int) $build->size_bytes : null,
            'bundle_identifier' => $artifact->signingBundleIdentifier($team),
            'shared' => $build->isShared(),
        ];
    }

    /** @param  list<ArtifactStatus>  $states */
    private function copy(array $states): AppArtifact
    {
        $copy = AppArtifact::query()->findOrFail((int) $this->argument('target'));
        if ($copy->derived_from_artifact_id === null) {
            throw new RuntimeException('Artifact is not a cleaned copy');
        }
        if (! in_array($copy->status, $states, true)) {
            throw new RuntimeException('Cleaned copy is '.$copy->status->value);
        }

        return $copy;
    }

    /** @param  Collection<int, SignedBuild>  $builds */
    private function retire(Collection $builds, string $reason): int
    {
        $states = app(StateMachine::class);
        $installations = app(InstallationService::class);
        $retired = 0;
        foreach ($builds as $build) {
            DB::transaction(function () use ($build, $reason, $states, $installations, &$retired) {
                $locked = SignedBuild::query()->whereKey($build->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== SignedBuildStatus::Deliverable) {
                    return;
                }
                $states->transition($locked, SignedBuildStatus::Expired, 'Device test: signing changed.', Actor::system('device-test'), extra: ['status_reason' => $reason]);
                $installations->buildReclaimed($locked, 'BUILD_EXPIRED');
                $retired++;
            });
        }

        return $retired;
    }

    private function device(): Device
    {
        return Device::query()->findOrFail((int) $this->option('device'));
    }

    private function user(): User
    {
        return User::query()->findOrFail((int) $this->option('user'));
    }
}
