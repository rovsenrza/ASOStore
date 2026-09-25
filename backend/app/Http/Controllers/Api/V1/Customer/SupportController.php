<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Privacy\AccountDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Support requests and the customer's own data (IMPLEMENTATION_PLAN P8-WEB-01, P8-SEC-02).
 */
class SupportController extends Controller
{
    public function __construct(
        private readonly AccountDataService $accounts,
        private readonly AuditService $audit,
    ) {}

    /**
     * Open to signed-out visitors too: a customer locked out of the app must still reach support.
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();
        $data = $request->validate([
            'email' => [$user instanceof User ? 'nullable' : 'required', 'email', 'max:255'],
            'topic' => ['required', Rule::in(SupportTicket::TOPICS)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'reference_request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'email' => $user instanceof User ? $user->email : $data['email'],
            'topic' => $data['topic'],
            'message' => $data['message'],
            'reference_request_id' => $data['reference_request_id'] ?? null,
            'ip' => $request->ip(),
        ]);
        $this->audit->record('support.ticket_created', $ticket, after: ['topic' => $ticket->topic], actor: $user instanceof User ? Actor::user($user) : new Actor(ActorType::Anonymous));

        return ApiResponse::ok(['id' => $ticket->public_id, 'status' => $ticket->status], 201);
    }

    public function export(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->accounts->export($request->user()));
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $ticket = $this->accounts->requestDeletion($request->user(), $request->ip());

        return ApiResponse::ok(['ticket_id' => $ticket->public_id, 'deletion_requested_at' => $request->user()->refresh()->deletion_requested_at?->toIso8601ZuluString()], 202);
    }
}
