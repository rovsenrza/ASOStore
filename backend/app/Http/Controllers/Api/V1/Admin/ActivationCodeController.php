<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivationCodeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivationCodeResource;
use App\Http\Responses\ApiResponse;
use App\Models\ActivationCode;
use App\Services\Activation\ActivationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ActivationCodeController extends Controller
{
    public function __construct(private readonly ActivationCodeService $codes) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ActivationCodeStatus::class)],
            'batch' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:8'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ActivationCode::query()->with(ActivationCodeResource::RELATIONS)->latest('id');

        match ($filters['status'] ?? null) {
            null => null,
            'EXPIRED' => $query->where(fn ($expired) => $expired
                ->where('status', 'EXPIRED')
                ->orWhere(fn ($lapsed) => $lapsed->where('status', ActivationCodeStatus::Issued->value)->where('expires_at', '<=', now()))),
            'ISSUED' => $query->where('status', 'ISSUED')->where(fn ($valid) => $valid->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            default => $query->where('status', $filters['status']),
        };
        if (filled($filters['batch'] ?? null)) {
            $query->where('batch_id', strtolower($filters['batch']));
        }
        if (filled($filters['q'] ?? null)) {
            $query->where('code_hint', strtoupper($filters['q']));
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, ActivationCodeResource::collection($page->items())->resolve($request));
    }

    /**
     * Returns the plaintext codes exactly once.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.config('storefront.activation.max_batch')],
            'plan' => ['nullable', 'string', 'alpha_dash', 'max:32'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = $this->codes->generate(
            $request->user(),
            (int) $data['count'],
            $data['plan'] ?? 'standard',
            isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            $data['note'] ?? null,
        );

        return ApiResponse::ok($batch + ['count' => count($batch['codes'])], 201);
    }

    public function revoke(Request $request, ActivationCode $activationCode): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $code = $this->codes->revoke($activationCode, $request->user(), $data['reason']);

        return ApiResponse::ok((new ActivationCodeResource($code->load(ActivationCodeResource::RELATIONS)))->resolve($request));
    }
}
