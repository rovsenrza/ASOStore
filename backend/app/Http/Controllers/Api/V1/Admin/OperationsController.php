<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Operations\MetricsCollector;
use App\Services\Privacy\AccountDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Metrics, support tickets and account erasure for operators (IMPLEMENTATION_PLAN Phase 8).
 */
class OperationsController extends Controller
{
    public function metrics(MetricsCollector $metrics): JsonResponse
    {
        return ApiResponse::ok($metrics->latest());
    }

    public function tickets(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:OPEN,CLOSED']])['status'] ?? 'OPEN';
        $page = SupportTicket::query()->with('user')->where('status', $status)->latest('id')->paginate(25);

        return ApiResponse::paginated($page, array_map(fn (SupportTicket $ticket) => [
            'id' => $ticket->public_id,
            'topic' => $ticket->topic,
            'email' => $ticket->email,
            'user_id' => $ticket->user?->public_id,
            'message' => $ticket->message,
            'reference_request_id' => $ticket->reference_request_id,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601ZuluString(),
        ], $page->items()));
    }

    public function closeTicket(Request $request, SupportTicket $ticket, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $ticket->forceFill(['status' => 'CLOSED'])->save();
        $audit->record('support.ticket_closed', $ticket, reason: $data['reason']);

        return ApiResponse::ok(['id' => $ticket->public_id, 'status' => $ticket->status]);
    }

    /**
     * Irreversible account erasure (P8-SEC-02). The operator types the email as confirmation.
     */
    public function erase(Request $request, User $user, AccountDataService $accounts): JsonResponse
    {
        $data = $request->validate([
            'confirm_email' => ['required', 'string', 'in:'.$user->email],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $accounts->erase($user, $request->user(), $data['reason']);

        return ApiResponse::ok(['id' => $user->public_id, 'erased_at' => $user->refresh()->erased_at?->toIso8601ZuluString()]);
    }
}
