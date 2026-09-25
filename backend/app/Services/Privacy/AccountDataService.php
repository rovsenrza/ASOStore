<?php

namespace App\Services\Privacy;

use App\Enums\DeviceRegistrationStatus;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\Installation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Auth\TokenService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * User data export and deletion (FULL_PLAN §13, IMPLEMENTATION_PLAN P8-SEC-02).
 *
 * Apple devices cannot be deleted inside a membership year, only disabled, so
 * erasure disables the registrations here and leaves the encrypted UDID until
 * the year has ended (RetentionService purges it then). Audit rows are
 * immutable and keep only masked identifiers.
 */
class AccountDataService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TokenService $tokens,
        private readonly StateMachine $states,
    ) {}

    /**
     * Everything stored about the user, in a portable structure. UDIDs are
     * masked here too: the full value is only ever needed by Apple.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $this->audit->record('account.exported', $user, actor: Actor::user($user));

        return [
            'exported_at' => now()->toIso8601ZuluString(),
            'account' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601ZuluString(),
                'deletion_requested_at' => $user->deletion_requested_at?->toIso8601ZuluString(),
            ],
            'subscriptions' => $user->subscriptions()->get()->map(fn ($subscription) => [
                'plan' => $subscription->plan,
                'status' => $subscription->status->value,
                'starts_at' => $subscription->starts_at->toIso8601ZuluString(),
                'ends_at' => $subscription->ends_at?->toIso8601ZuluString(),
            ])->all(),
            'devices' => Device::query()->with('registrations')->where('user_id', $user->id)->get()->map(fn (Device $device) => [
                'id' => $device->public_id,
                'udid_hint' => $device->maskedUdid(),
                'product' => $device->product,
                'os_version' => $device->os_version,
                'enrolled_at' => $device->enrolled_at?->toIso8601ZuluString(),
                'registrations' => $device->registrations->map(fn ($registration) => [
                    'status' => $registration->status->value,
                    'registered_at' => $registration->registered_at?->toIso8601ZuluString(),
                ])->all(),
            ])->all(),
            'installations' => Installation::query()->with(['app', 'artifact'])->where('user_id', $user->id)->get()->map(fn (Installation $installation) => [
                'app' => $installation->app->name,
                'version' => $installation->artifact->version,
                'status' => $installation->status->value,
                'created_at' => $installation->created_at?->toIso8601ZuluString(),
                'delivered_at' => $installation->delivered_at?->toIso8601ZuluString(),
            ])->all(),
            'support_tickets' => SupportTicket::query()->where('user_id', $user->id)->get()->map(fn (SupportTicket $ticket) => [
                'topic' => $ticket->topic,
                'message' => $ticket->message,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toIso8601ZuluString(),
            ])->all(),
        ];
    }

    /**
     * The customer asks for deletion; an operator performs it (identity and
     * open obligations are checked by a person, not automatically).
     */
    public function requestDeletion(User $user, ?string $ip): SupportTicket
    {
        return DB::transaction(function () use ($user, $ip) {
            $user->forceFill(['deletion_requested_at' => $user->deletion_requested_at ?? now()])->save();
            $ticket = SupportTicket::query()->firstOrCreate(
                ['user_id' => $user->id, 'topic' => 'DATA_DELETION', 'status' => 'OPEN'],
                ['email' => $user->email, 'message' => 'Account deletion requested from the portal.', 'ip' => $ip],
            );
            $this->audit->record('account.deletion_requested', $user, actor: Actor::user($user));

            return $ticket;
        });
    }

    /**
     * Irreversible. Personal data is replaced, sessions end, devices stop being
     * usable and their Apple registrations are disabled for the rest of the year.
     */
    public function erase(User $user, User $operator, string $reason): User
    {
        return DB::transaction(function () use ($user, $operator, $reason) {
            $actor = Actor::user($operator);

            $this->tokens->revokeAllForUser($user);
            $user->tokens()->delete();

            foreach (Device::query()->with('registrations')->where('user_id', $user->id)->get() as $device) {
                foreach ($device->registrations as $registration) {
                    if ($registration->status->canTransitionTo(DeviceRegistrationStatus::Disabled)) {
                        $this->states->transition($registration, DeviceRegistrationStatus::Disabled, 'Account erased.', $actor, extra: ['status_reason' => 'ACCOUNT_ERASED']);
                    }
                }
                $device->forceFill(['name' => null])->save();
            }

            $user->forceFill([
                'name' => 'Удалённый пользователь',
                'email' => 'erased-'.$user->public_id.'@invalid.example',
                'password' => Hash::make(Str::random(64)),
                'status' => UserStatus::Suspended,
                'remember_token' => null,
                'erased_at' => now(),
            ])->save();

            SupportTicket::query()->where('user_id', $user->id)->update(['email' => $user->email, 'message' => '[erased]', 'status' => 'CLOSED']);
            $this->audit->record('account.erased', $user, reason: $reason, actor: $actor);

            return $user;
        });
    }
}
