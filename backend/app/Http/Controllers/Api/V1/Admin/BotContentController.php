<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\TelegramStore\StoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lets a store admin edit, from the web admin panel, the pieces of the Telegram bot that change
 * often: the greeting text, the brand name and the banner image every message carries. Values are
 * stored in StoreSettings (telegram_store_settings) and override the config defaults; the bot reads
 * them on its next update, so changes take effect without a deploy.
 */
class BotContentController extends Controller
{
    private const BANNER_DIR = 'telegram-store';

    public function __construct(private readonly StoreSettings $settings) {}

    public function show(): JsonResponse
    {
        $content = $this->settings->botContent();
        $default = (string) config('telegram_store.welcome_banner');

        return ApiResponse::ok([
            'welcome_text' => $content['welcome_text'],
            'welcome_text_is_custom' => $content['welcome_text'] !== null,
            'brand' => $content['brand'],
            'brand_default' => (string) config('telegram_store.brand', 'Ru App Store'),
            'banner_set' => $content['welcome_banner'] !== null,
            'banner_is_default' => $content['welcome_banner'] === null
                || ($default !== '' && $content['welcome_banner'] === $default),
            'banner_url' => route('api.admin.bot-content.banner').'?v='.($content['welcome_banner'] ? md5($content['welcome_banner'].'|'.@filemtime($content['welcome_banner'])) : '0'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // When a banner is shown the text is a photo caption (Telegram limit 1024); allow more for
            // the text-only case. The bot truncates/handles overflow, but keep a sane upper bound.
            'welcome_text' => ['present', 'nullable', 'string', 'max:3500'],
            'brand' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        $this->settings->setWelcomeText($data['welcome_text']);
        $this->settings->setBrand($data['brand']);

        return $this->show();
    }

    public function uploadBanner(Request $request): JsonResponse
    {
        $request->validate([
            'banner' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $file = $request->file('banner');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $dir = storage_path('app/'.self::BANNER_DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // One stable name per extension; replacing the banner overwrites it and the file's new mtime
        // makes a fresh Messenger file_id cache key, so Telegram re-uploads it.
        $name = 'welcome-banner.'.($ext === 'png' ? 'png' : 'jpg');
        $file->move($dir, $name);
        $path = $dir.'/'.$name;

        $this->settings->setWelcomeBanner($path);

        return $this->show();
    }

    public function resetBanner(): JsonResponse
    {
        $this->settings->setWelcomeBanner(null);

        return $this->show();
    }

    public function banner(): BinaryFileResponse|Response
    {
        $path = $this->settings->welcomeBanner();
        if ($path === null || ! is_file($path)) {
            return response('', 404);
        }

        return response()->file($path, ['Cache-Control' => 'no-cache, max-age=0']);
    }
}
