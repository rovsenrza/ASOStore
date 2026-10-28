<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppLaunchReport;
use App\Models\SignedBuild;
use App\Models\SigningProfile;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Launch reports from the compat shim inside re-signed apps: the launch held, the app crashed,
 * or it quit right after launching. Apps carry no credentials, so a report counts only when its
 * bundle ID is one we signed; anything else is accepted and dropped.
 */
class TelemetryController extends Controller
{
    public function launch(Request $request): Response
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', AppLaunchReport::KINDS)],
            'bundle' => ['required', 'string', 'max:190'],
            'original_bundle' => ['nullable', 'string', 'max:190'],
            'version' => ['nullable', 'string', 'max:32'],
            'os' => ['nullable', 'string', 'max:16'],
            'model' => ['nullable', 'string', 'max:32'],
            'shim' => ['nullable', 'string', 'max:16'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'backtrace' => ['nullable', 'array', 'max:64'],
            'backtrace.*' => ['string', 'max:300'],
            'at' => ['nullable', 'integer'],
        ]);

        $profile = SigningProfile::query()->where('bundle_identifier', $data['bundle'])->latest('id')->first();
        if ($profile === null) {
            return response()->noContent(202);
        }
        $appId = SignedBuild::query()->where('signing_profile_id', $profile->id)->latest('id')->with('artifact:id,app_id')->first()?->artifact?->app_id;

        $report = AppLaunchReport::query()->create([
            'kind' => $data['kind'],
            'bundle_identifier' => $data['bundle'],
            'original_bundle_identifier' => $data['original_bundle'] ?? null,
            'app_id' => $appId,
            'apple_team_id' => $profile->apple_team_id,
            'app_version' => $data['version'] ?? null,
            'os_version' => $data['os'] ?? null,
            'device_model' => $data['model'] ?? null,
            'shim_version' => $data['shim'] ?? null,
            'detail' => isset($data['detail']) ? Str::limit($data['detail'], 497) : null,
            'backtrace' => isset($data['backtrace']) ? implode("\n", $data['backtrace']) : null,
            'happened_at' => isset($data['at']) && $data['at'] > 0 && $data['at'] <= now()->addDay()->getTimestamp() ? now()->setTimestamp($data['at']) : null,
        ]);

        if ($report->kind !== AppLaunchReport::LAUNCHED) {
            $this->alert($report);
        }

        return response()->noContent(202);
    }

    /**
     * Diagnostics line from TGRelay.dylib inside re-signed Telegram: whether our MTProxy was forced
     * on, and any transport failure seen in the field (e.g. the TSPU ~16 KB freeze as a read error).
     * No credentials and no message content — just proxy state. Logged for `apps:tg-diag` / SSH.
     */
    public function tgDiag(Request $request): Response
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:64'],
            'detail' => ['nullable', 'string', 'max:500'],
            'udid' => ['nullable', 'string', 'max:64'],
            'bundleId' => ['nullable', 'string', 'max:190'],
            'appVersion' => ['nullable', 'string', 'max:32'],
        ]);

        Log::info('tg-relay.diag', [
            'event' => $data['event'],
            'detail' => $data['detail'] ?? null,
            'udid' => $data['udid'] ?? null,
            'bundle' => $data['bundleId'] ?? null,
            'version' => $data['appVersion'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->noContent(202);
    }

    /** One Telegram message per app, team and kind an hour, so a crash loop does not flood the admins. */
    private function alert(AppLaunchReport $report): void
    {
        if (! Cache::add("launch-alert:{$report->bundle_identifier}:{$report->kind}", true, now()->addHour())) {
            return;
        }
        $what = $report->kind === AppLaunchReport::CRASHED ? 'упало при запуске' : 'закрылось сразу после запуска';
        try {
            app(Messenger::class)->notifyAdmins(new Screen(sprintf(
                "⚠️ <b>%s</b> %s\n%s · iOS %s · команда %s\n<code>%s</code>\nПодробно: <code>php artisan apps:launch-reports %d</code>",
                e($report->app?->name ?? $report->bundle_identifier),
                $what,
                e($report->device_model ?? '?'),
                e($report->os_version ?? '?'),
                e($report->team?->apple_team_id ?? '?'),
                e(Str::limit((string) $report->detail, 300)),
                $report->id,
            )));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
