<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

/** Temporary ad-hoc OTA install for a customer-cert-signed build. Remove when done. */
class SignTestController extends Controller
{
    private function meta(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1, 404);
        abort_unless(Storage::disk('local')->exists("signtest/$token.json"), 404);
        return (array) json_decode(Storage::disk('local')->get("signtest/$token.json"), true);
    }

    public function page(string $token)
    {
        $m = $this->meta($token);
        $manifest = url("/sign-test/$token/manifest.plist");
        $itms = 'itms-services://?action=download-manifest&url='.rawurlencode($manifest);
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($m['title']).'</title>'
            .'<body style="font-family:-apple-system,system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 20px;text-align:center">'
            .'<h2>'.e($m['title']).'</h2><p style="color:#666">Версия '.e($m['version']).'</p>'
            .'<a href="'.e($itms).'" style="display:inline-block;background:#0a84ff;color:#fff;text-decoration:none;padding:14px 28px;border-radius:12px;font-size:18px;margin-top:12px">Установить</a>'
            .'<p style="color:#999;font-size:13px;margin-top:24px">Откройте эту страницу в Safari на iPhone и нажмите «Установить».</p></body>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function manifest(string $token)
    {
        $m = $this->meta($token);
        $ipaUrl = url("/sign-test/$token/app.ipa");
        $plist = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'
            .'<plist version="1.0"><dict><key>items</key><array><dict>'
            .'<key>assets</key><array><dict><key>kind</key><string>software-package</string>'
            .'<key>url</key><string>'.htmlspecialchars($ipaUrl, ENT_XML1).'</string></dict></array>'
            .'<key>metadata</key><dict>'
            .'<key>bundle-identifier</key><string>'.htmlspecialchars($m['bundle_id'], ENT_XML1).'</string>'
            .'<key>bundle-version</key><string>'.htmlspecialchars($m['version'], ENT_XML1).'</string>'
            .'<key>kind</key><string>software</string>'
            .'<key>title</key><string>'.htmlspecialchars($m['title'], ENT_XML1).'</string>'
            .'</dict></dict></array></dict></plist>';
        return response($plist, 200, ['Content-Type' => 'text/xml; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function ipa(string $token)
    {
        $this->meta($token);
        $path = storage_path("app/private/signtest/$token.ipa");
        abort_unless(is_file($path), 404);
        return response()->download($path, 'app.ipa', ['Content-Type' => 'application/octet-stream']);
    }
}
