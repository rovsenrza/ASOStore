<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TelegramStoreCustomer;
use App\Services\Audit\AuditService;
use App\Services\TelegramStore\Bot\CustomerScreens;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets a store admin grow the bot without a developer: own pages (menu button, /command,
 * keywords → text with an optional link), hide standard menu buttons, and choose who else
 * may use the bot's /admin.
 */
class BotBuilderController extends Controller
{
    /** Tags Telegram accepts in HTML parse mode. */
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'a', 'code', 'pre', 'blockquote', 'tg-spoiler', 'span'];

    /** Standard menu buttons that may be hidden (buying and renewal always stay). */
    public const HIDEABLE = ['profile' => '👤 Профиль', 'invite' => '👥 Пригласить друга', 'help' => '📖 Инструкция', 'news' => '📰 Новости', 'support' => '💬 Поддержка', 'legal' => '🔒 Конфиденциальность / 📄 Соглашение'];

    /** Commands the bot already has. */
    private const RESERVED = ['start', 'menu', 'buy', 'profile', 'invite', 'help', 'renew', 'admin', 'cancel', 'paid', 'promo', 'user'];

    public function __construct(
        private readonly StoreSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::ok([
            'blocks' => $this->settings->botBlocks(),
            'hidden_menu_items' => $this->settings->hiddenMenuItems(),
            'hideable' => collect(self::HIDEABLE)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'reserved_commands' => self::RESERVED,
        ]);
    }

    /** Saves the whole list at once (order = order in the menu). */
    public function update(Request $request, TelegramApi $api): JsonResponse
    {
        $data = $request->validate([
            'blocks' => ['present', 'array', 'max:40'],
            'blocks.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,40}$/'],
            'blocks.*.label' => ['required', 'string', 'max:40'],
            'blocks.*.text' => ['required', 'string', 'max:3500'],
            'blocks.*.url' => ['nullable', 'url:https,http,tg', 'max:500'],
            'blocks.*.url_label' => ['nullable', 'string', 'max:40'],
            'blocks.*.command' => ['nullable', 'string', 'regex:/^\/?[a-zA-Z0-9_]{1,32}$/'],
            'blocks.*.keywords' => ['nullable', 'array', 'max:20'],
            'blocks.*.keywords.*' => ['string', 'min:2', 'max:60'],
            'blocks.*.in_menu' => ['boolean'],
            'blocks.*.enabled' => ['boolean'],
            'hidden_menu_items' => ['present', 'array'],
            'hidden_menu_items.*' => ['in:'.implode(',', array_keys(self::HIDEABLE))],
        ]);

        $blocks = [];
        $ids = [];
        $commands = [];
        foreach ($data['blocks'] as $index => $raw) {
            $problem = $this->htmlProblem($raw['text']);
            if ($problem !== null) {
                throw new ApiException(ErrorCode::ValidationFailed, "Раздел «{$raw['label']}»: {$problem}", ['fields' => ["blocks.{$index}.text" => [$problem]]]);
            }
            $id = $raw['id'] ?? null;
            if (! $id || isset($ids[$id])) {
                $id = Str::slug($raw['label'], '_', 'ru') ?: 'page';
                $id = substr($id, 0, 30);
                $base = $id;
                for ($n = 2; isset($ids[$id]); $n++) {
                    $id = $base.'_'.$n;
                }
            }
            $ids[$id] = true;
            $command = isset($raw['command']) && $raw['command'] !== '' ? ltrim(strtolower($raw['command']), '/') : null;
            if ($command !== null && (in_array($command, self::RESERVED, true) || isset($commands[$command]))) {
                throw new ApiException(ErrorCode::ValidationFailed, "Команда /{$command} уже занята.", ['fields' => ["blocks.{$index}.command" => ['Команда занята.']]]);
            }
            if ($command !== null) {
                $commands[$command] = true;
            }
            $blocks[] = [
                'id' => $id,
                'label' => trim($raw['label']),
                'text' => self::escapeStray(trim($raw['text'])),
                'url' => ($raw['url'] ?? null) ?: null,
                'url_label' => ($raw['url_label'] ?? null) ?: null,
                'command' => $command,
                'keywords' => array_values(array_unique(array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), $raw['keywords'] ?? [])))),
                'in_menu' => (bool) ($raw['in_menu'] ?? true),
                'enabled' => (bool) ($raw['enabled'] ?? true),
            ];
        }

        $before = ['blocks' => count($this->settings->botBlocks()), 'hidden' => $this->settings->hiddenMenuItems()];
        $this->settings->setBotBlocks($blocks);
        $this->settings->setHiddenMenuItems($data['hidden_menu_items']);
        $this->audit->record('store.bot_builder_saved', null, before: $before, after: ['blocks' => array_column($blocks, 'label'), 'hidden' => $data['hidden_menu_items']]);

        // The «/» menu in Telegram shows the new commands; a failure here is not fatal.
        try {
            $api->setCommands();
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->show();
    }

    /** Sends one page, as customers will see it, to a bot admin (to check formatting). */
    public function preview(Request $request, TelegramApi $api, CustomerScreens $screens): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'text' => ['required', 'string', 'max:3500'],
            'url' => ['nullable', 'url:https,http,tg', 'max:500'],
            'url_label' => ['nullable', 'string', 'max:40'],
            'telegram_user_id' => ['nullable', 'integer'],
        ]);
        if (($problem = $this->htmlProblem($data['text'])) !== null) {
            throw new ApiException(ErrorCode::ValidationFailed, $problem);
        }
        $to = (int) ($data['telegram_user_id'] ?? $this->settings->firstAdminId());
        if (! $this->settings->isAdmin($to)) {
            throw new ApiException(ErrorCode::Conflict, 'Предпросмотр отправляется только администратору бота.');
        }
        $screen = $screens->block(['text' => self::escapeStray($data['text']), 'url' => $data['url'] ?? null, 'url_label' => $data['url_label'] ?? null]);
        try {
            $api->sendMessage($to, $screen->text, $screen->keyboard());
        } catch (Throwable $exception) {
            throw new ApiException(ErrorCode::Conflict, 'Telegram не принял сообщение: '.$exception->getMessage());
        }

        return ApiResponse::ok(['sent_to' => $to]);
    }

    /* ---------- Bot admins ---------- */

    public function admins(): JsonResponse
    {
        $env = array_map('strval', config('telegram_store.admin_ids', []));
        $ids = $this->settings->adminIds();
        $customers = TelegramStoreCustomer::query()->whereIn('telegram_user_id', array_map('intval', $ids))->get()->keyBy('telegram_user_id');

        return ApiResponse::ok(array_map(fn (string $id) => [
            'telegram_user_id' => (int) $id,
            'username' => $customers[(int) $id]->username ?? null,
            'first_name' => $customers[(int) $id]->first_name ?? null,
            'started_bot' => isset($customers[(int) $id]),
            'locked' => in_array($id, $env, true),
        ], $ids));
    }

    /** Adds a bot admin by @username (they must have opened the bot once) or numeric Telegram id. */
    public function addAdmin(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => ['required', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:500']]);
        $query = ltrim(trim($data['query']), '@');
        $id = ctype_digit($query)
            ? (int) $query
            : (int) (TelegramStoreCustomer::query()->where('username', $query)->value('telegram_user_id') ?? 0);
        if ($id <= 0) {
            throw new ApiException(ErrorCode::NotFound, "Пользователь @{$query} не найден. Попросите его открыть бота и нажать /start, или укажите числовой Telegram ID.");
        }
        $ids = $this->settings->panelAdminIds();
        if (! in_array((string) $id, $ids, true)) {
            $ids[] = (string) $id;
            $this->settings->setPanelAdminIds($ids);
            $this->audit->record('store.bot_admin_added', null, after: ['telegram_user_id' => $id], reason: $data['reason'] ?? null);
        }

        return $this->admins();
    }

    public function removeAdmin(Request $request, int $telegramUserId): JsonResponse
    {
        if (in_array((string) $telegramUserId, array_map('strval', config('telegram_store.admin_ids', [])), true)) {
            throw new ApiException(ErrorCode::Conflict, 'Этот администратор задан на сервере (.env) — его можно убрать только там.');
        }
        $ids = array_values(array_filter($this->settings->panelAdminIds(), fn ($id) => $id !== (string) $telegramUserId));
        $this->settings->setPanelAdminIds($ids);
        $this->audit->record('store.bot_admin_removed', null, after: ['telegram_user_id' => $telegramUserId]);

        return $this->admins();
    }

    /** Escapes a lone & or < (e.g. «цена < 500») that Telegram would reject as broken HTML. */
    public static function escapeStray(string $text): string
    {
        $text = preg_replace('/&(?!(?:amp|lt|gt|quot|#\d+);)/', '&amp;', $text) ?? $text;

        return preg_replace('/<(?!\/?[a-zA-Z])/', '&lt;', $text) ?? $text;
    }

    /** Null when Telegram will accept the HTML; otherwise what is wrong. */
    private function htmlProblem(string $text): ?string
    {
        if (! preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9-]*)[^>]*>/', $text, $matches, PREG_SET_ORDER)) {
            return null;
        }
        $stack = [];
        foreach ($matches as [$whole, $closing, $tag]) {
            $tag = strtolower($tag);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                return "тег <{$tag}> Telegram не поддерживает. Можно: b, i, u, s, a, code, pre, blockquote, tg-spoiler.";
            }
            if ($closing === '') {
                $stack[] = $tag;
            } elseif (array_pop($stack) !== $tag) {
                return "тег <{$tag}> закрыт не по порядку.";
            }
        }

        return $stack === [] ? null : 'не закрыт тег <'.end($stack).'>.';
    }
}
