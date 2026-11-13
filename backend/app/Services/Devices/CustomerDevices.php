<?php

namespace App\Services\Devices;

use App\Enums\DeviceRegistrationStatus as Registration;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The customer's iPhones as the website, the app and the bot show them: each with its own
 * access (active, until when), whether it is connected yet and where its Apple registration
 * stands. Never the UDID, only its last four characters.
 */
class CustomerDevices
{
    /** @return list<array<string, mixed>> connected iPhones first (newest first), then pending ones */
    public function list(User $user): array
    {
        return $user->devices()->with(['latestRegistration', 'activeSubscription'])->get()
            ->sortBy(fn (Device $device) => [$device->isEnrolled() ? 0 : 1, -$device->id])
            ->map(fn (Device $device) => $this->present($device))->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(Device $device): array
    {
        $device->loadMissing(['latestRegistration', 'activeSubscription']);
        $active = $device->activeSubscription !== null;
        $unlimited = $active && $device->hasUnlimitedAccess();
        $end = $unlimited ? null : $device->accessEndsAt();
        $registration = $device->latestRegistration;

        return \App\Services\Storefront\StorefrontStatusResolver::presentDevice($device) + [
            'name' => $device->name,
            'model' => self::model($device->product),
            'connected' => $device->isEnrolled(),
            'method' => $device->registration_method === 'INSTANT' ? 'instant' : 'apple',
            'access' => [
                'active' => $active,
                'unlimited' => $unlimited,
                'ends_at' => $end?->toIso8601ZuluString(),
                'days_left' => $active && $end ? max(0, (int) ceil(now()->diffInHours($end, false) / 24)) : null,
            ],
            // ready: apps can be installed; waiting: Apple is processing; blocked: needs support;
            // connect: paid, install the profile on the new iPhone; expired: renew.
            'state' => match (true) {
                ! $active => 'expired',
                ! $device->isEnrolled() => 'connect',
                $registration?->status === Registration::Eligible => 'ready',
                in_array($registration?->status, [null, Registration::Enrolled, Registration::ApplePending], true) => 'waiting',
                default => 'blocked',
            },
        ];
    }

    /** «iPhone 16 Pro» from «iPhone17,1»; null when unknown. */
    public static function model(?string $product): ?string
    {
        return $product === null ? null : (self::MODELS[$product] ?? (str_starts_with($product, 'iPad') ? 'iPad' : (str_starts_with($product, 'iPhone') ? 'iPhone' : $product)));
    }

    private const MODELS = [
        'iPhone10,1' => 'iPhone 8', 'iPhone10,4' => 'iPhone 8', 'iPhone10,2' => 'iPhone 8 Plus', 'iPhone10,5' => 'iPhone 8 Plus',
        'iPhone10,3' => 'iPhone X', 'iPhone10,6' => 'iPhone X', 'iPhone11,2' => 'iPhone XS', 'iPhone11,4' => 'iPhone XS Max',
        'iPhone11,6' => 'iPhone XS Max', 'iPhone11,8' => 'iPhone XR', 'iPhone12,1' => 'iPhone 11', 'iPhone12,3' => 'iPhone 11 Pro',
        'iPhone12,5' => 'iPhone 11 Pro Max', 'iPhone12,8' => 'iPhone SE', 'iPhone13,1' => 'iPhone 12 mini', 'iPhone13,2' => 'iPhone 12',
        'iPhone13,3' => 'iPhone 12 Pro', 'iPhone13,4' => 'iPhone 12 Pro Max', 'iPhone14,4' => 'iPhone 13 mini', 'iPhone14,5' => 'iPhone 13',
        'iPhone14,2' => 'iPhone 13 Pro', 'iPhone14,3' => 'iPhone 13 Pro Max', 'iPhone14,6' => 'iPhone SE', 'iPhone14,7' => 'iPhone 14',
        'iPhone14,8' => 'iPhone 14 Plus', 'iPhone15,2' => 'iPhone 14 Pro', 'iPhone15,3' => 'iPhone 14 Pro Max', 'iPhone15,4' => 'iPhone 15',
        'iPhone15,5' => 'iPhone 15 Plus', 'iPhone16,1' => 'iPhone 15 Pro', 'iPhone16,2' => 'iPhone 15 Pro Max', 'iPhone17,1' => 'iPhone 16 Pro',
        'iPhone17,2' => 'iPhone 16 Pro Max', 'iPhone17,3' => 'iPhone 16', 'iPhone17,4' => 'iPhone 16 Plus', 'iPhone17,5' => 'iPhone 16e',
        'iPhone18,1' => 'iPhone 17 Pro', 'iPhone18,2' => 'iPhone 17 Pro Max', 'iPhone18,3' => 'iPhone 17', 'iPhone18,4' => 'iPhone Air',
    ];
}
