<?php

namespace App\Http\Controllers;

use App\Services\Devices\EnrollmentPayloadParser;
use App\Services\Devices\EnrollmentProfileBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Temporary UDID-capture probe. Serves the same Profile Service .mobileconfig the real enrollment
 * uses, but the callback ONLY shows the device UDID — it never creates a Device, a registration,
 * or routes to any Apple team. Gated by an unguessable token in the URL. Remove when done.
 */
class UdidProbeController extends Controller
{
    public function __construct(
        private readonly EnrollmentProfileBuilder $profiles,
        private readonly EnrollmentPayloadParser $payloads,
    ) {}

    public function profile(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1, 404);
        $xml = $this->profiles->build(url('/udid-probe/'.$token.'/callback'), $token);
        $signed = $this->profiles->sign($xml);

        return response($signed ?? $xml, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="udid-probe.mobileconfig"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function callback(Request $request, string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1, 404);
        try {
            $payload = $this->payloads->parse($request->getContent());
        } catch (\Throwable $e) {
            return redirect('/udid-probe/'.$token.'/result?err=parse');
        }
        if (! hash_equals($token, $payload['CHALLENGE'] ?? '')) {
            return redirect('/udid-probe/'.$token.'/result?err=challenge');
        }
        $udid = strtoupper(trim($payload['UDID'] ?? ''));
        $key = 'udid-probe:'.$token;
        $captured = Cache::get($key, []);
        $captured[$udid] = ['udid' => $udid, 'product' => (string) ($payload['PRODUCT'] ?? ''), 'version' => (string) ($payload['VERSION'] ?? ''), 'at' => now()->toDateTimeString()];
        Cache::put($key, $captured, now()->addHours(12));

        return redirect('/udid-probe/'.$token.'/result');
    }

    public function result(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1, 404);
        $captured = Cache::get('udid-probe:'.$token, []);
        $rows = '';
        foreach ($captured as $c) {
            $rows .= '<tr><td style="font-family:ui-monospace,monospace;font-size:17px;padding:10px 12px;user-select:all;-webkit-user-select:all">'.e($c['udid']).'</td><td style="padding:10px 12px">'.e($c['product']).'</td><td style="padding:10px 12px">'.e($c['version']).'</td><td style="padding:10px 12px;color:#888">'.e($c['at']).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="padding:18px;color:#888">Ждём устройство… установите профиль на iPhone (Safari), затем обновите эту страницу.</td></tr>';
        }
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UDID probe</title>'
            .'<body style="font-family:-apple-system,system-ui,sans-serif;max-width:760px;margin:24px auto;padding:0 16px">'
            .'<h2>UDID probe</h2>'
            .'<table style="border-collapse:collapse;width:100%;border:1px solid #eee">'
            .'<tr style="text-align:left;background:#f4f4f5"><th style="padding:10px 12px">UDID</th><th style="padding:10px 12px">Модель</th><th style="padding:10px 12px">iOS</th><th style="padding:10px 12px">Время</th></tr>'
            .$rows.'</table>'
            .'<p style="color:#888;font-size:13px;margin-top:16px">Устройство НЕ зарегистрировано ни в одном dev-аккаунте — показан только UDID.</p>'
            .'</body>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
