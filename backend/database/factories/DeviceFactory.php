<?php

namespace Database\Factories;

use App\Enums\DeviceFamily;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product' => 'iPhone15,2',
            'device_family' => DeviceFamily::Iphone,
            'os_version' => '18.6',
            'name' => 'iPhone',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Device $device) {
            $device->setUdid(strtoupper(fake()->unique()->regexify('[0-9A-F]{8}-[0-9A-F]{16}')));
        });
    }
}
