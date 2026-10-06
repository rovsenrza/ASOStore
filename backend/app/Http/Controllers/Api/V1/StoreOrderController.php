<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\Bot\Format;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\PlategaClient;
use App\Services\TelegramStore\Payments\PlategaException;
use App\Services\TelegramStore\Payments\PlategaPayments;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Online payment of store orders (Platega): the website's «Оплатить» button, the page
 * Platega sends the payer back to, and Platega's callback.
 */
class StoreOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PlategaPayments $payments,
    ) {}

    /** Opens an order for the signed-in account and returns Platega's payment page. */
    public function checkout(Request $request, StoreSettings $settings): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:16']]);
        if ($settings->plan($data['plan']) === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Этот тариф больше недоступен.', ['fields' => ['plan' => ['Этот тариф больше недоступен.']]]);
        }
        if (! PlategaClient::configured()) {
            throw new ApiException(ErrorCode::ServiceUnavailable, 'Оплата на сайте временно недоступна.');
        }

        $order = $this->orders->createForUser($request->user(), $data['plan']);
        try {
            $url = $this->payments->paymentUrl($order, $request->ip());
        } catch (PlategaException $exception) {
            report($exception);
            $this->orders->close($order, TelegramStoreOrder::CANCELLED);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'Платёжная система не ответила. Попробуйте ещё раз через минуту.');
        }

        return ApiResponse::ok(['order' => $this->present($order->refresh()), 'payment_url' => $url], 201);
    }

    /**
     * The order's state for the payment result page. Public: the payer may come back in a
     * browser without the session, and the 26-character ULID is not guessable. Asks Platega
     * at most every few seconds while the payment has not arrived yet.
     */
    public function show(string $order): JsonResponse
    {
        $record = preg_match('/^[0-9a-zA-Z]{26}$/', $order) === 1 ? $this->orders->find($order) : null;
        if ($record === null) {
            throw new ApiException(ErrorCode::NotFound, 'Заказ не найден.');
        }

        if ($record->status !== TelegramStoreOrder::PAID && $record->payment_reference
            && Cache::add('platega.check.'.$record->id, true, now()->addSeconds(5))) {
            $this->payments->check($record);
            $record->refresh();
        }

        return ApiResponse::ok(['order' => $this->present($record)]);
    }

    /**
     * Platega's callback (Настройки → Callback URLs). It carries our merchant ID and secret;
     * the transaction's state is then read from Platega itself. An error answer makes Platega
     * retry (3 times, 5 minutes apart), and the reconcile sweep catches the rest.
     */
    public function callback(Request $request): JsonResponse
    {
        if (! app(PlategaClient::class)->authentic($request->header('X-MerchantId'), $request->header('X-Secret'))) {
            throw new ApiException(ErrorCode::Unauthenticated, 'Unknown merchant credentials.');
        }
        $id = $request->input('id');
        if (! is_string($id) || preg_match('/^[0-9A-Za-z-]{8,64}$/', $id) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Transaction id is missing.');
        }

        try {
            $this->payments->settle($id);
        } catch (PlategaException $exception) {
            report($exception);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'Could not read the transaction from Platega.');
        }

        return ApiResponse::ok(['received' => true]);
    }

    /** @return array<string, mixed> */
    private function present(TelegramStoreOrder $order): array
    {
        return [
            'id' => $order->public_id,
            'reference' => $order->shortReference(),
            'status' => $order->status,
            'channel' => $order->isWeb() ? 'web' : 'telegram',
            'plan' => $order->plan_key,
            'term' => Format::days($order->duration_days),
            'duration_days' => $order->duration_days,
            'amount' => $order->amount_due_rub,
            'currency' => 'RUB',
            'expires_at' => $order->expires_at->toIso8601ZuluString(),
            'paid_at' => $order->paid_at?->toIso8601ZuluString(),
            'bot_url' => $order->isWeb() ? null : $this->botUrl(),
        ];
    }

    private function botUrl(): ?string
    {
        try {
            $username = app(TelegramApi::class)->botUsername();
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^\w{5,32}$/', $username) === 1 ? "https://t.me/{$username}" : null;
    }
}
