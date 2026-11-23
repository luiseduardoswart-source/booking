<?php

namespace App\Services\SmartLocks;

use App\Models\Setting;
use App\Support\RequestMemory;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Seam (seam.co): one connection for the smart locks of many brands (August,
 * Yale, Schlage, Kwikset, Nuki, Tedee, igloohome, TTLock...). The owner
 * connects their locks in their own Seam account; the site only needs its API
 * key (Settings → Keys). Methods as in Seam's own PHP library:
 *
 * - GET    /devices/list         → `devices[]` with `device_id` and `display_name`;
 * - GET    /access_codes/list    → `access_codes[]` of a lock, each with `access_code_id`, `name` and `code`;
 * - POST   /access_codes/create  → `access_code` with `access_code_id` and `code`;
 * - GET    /access_codes/get     → the same `access_code`, also once the lock made the code itself;
 * - DELETE /access_codes/delete  → removed (a code already gone answers 404).
 *
 * A failed call is logged without the key or the code and returns null or
 * false: the caller decides what the guest gets then.
 */
class SeamLocks
{
    private const BASE_URL = 'https://connect.getseam.com';

    /** How long the list of locks is remembered for the admin form. */
    private const DEVICES_MINUTES = 5;

    public function configured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * The locks in the owner's Seam account: device id => name. Remembered
     * for a few minutes: the admin form asks for them at every change. A
     * failed answer is not remembered.
     *
     * @return array<string, string>
     */
    public function devices(): array
    {
        if (! $this->configured()) {
            return [];
        }

        $cacheKey = 'seam.devices.'.hash('sha256', (string) $this->apiKey());

        return RequestMemory::remember($cacheKey, function () use ($cacheKey): array {
            if (is_array($cached = Cache::get($cacheKey))) {
                return $cached;
            }

            $response = $this->call(fn (PendingRequest $http) => $http->get(self::BASE_URL.'/devices/list'));

            if ($response === null) {
                return [];
            }

            $devices = collect($response->json('devices') ?? [])
                ->filter(fn ($device) => is_array($device) && filled($device['device_id'] ?? null))
                ->mapWithKeys(fn (array $device) => [(string) $device['device_id'] => (string) ($device['display_name'] ?? $device['properties']['name'] ?? $device['device_id'])])
                ->sort()
                ->all();

            Cache::put($cacheKey, $devices, now()->addMinutes(self::DEVICES_MINUTES));

            return $devices;
        });
    }

    /**
     * The code on the lock that carries this name, if there is one: a code
     * made earlier whose answer never arrived (connection broken off).
     * Null when Seam cannot be asked; then nothing should be made either.
     *
     * @return array{found: bool, id?: string, code?: ?string}|null
     */
    public function findCode(string $deviceId, string $name): ?array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get(self::BASE_URL.'/access_codes/list', ['device_id' => $deviceId]));

        if ($response === null) {
            return null;
        }

        $code = collect($response->json('access_codes') ?? [])
            // Not one that is being taken off the lock.
            ->first(fn ($code) => is_array($code) && ($code['name'] ?? null) === $name && filled($code['access_code_id'] ?? null) && ($code['status'] ?? null) !== 'removing');

        return $code === null
            ? ['found' => false]
            : ['found' => true, 'id' => (string) $code['access_code_id'], 'code' => is_scalar($code['code'] ?? null) && (string) $code['code'] !== '' ? (string) $code['code'] : null];
    }

    /**
     * A code that only opens the lock between the two moments. The lock (or
     * Seam) chooses the digits: not every lock lets you choose them.
     *
     * @return array{id: string, code: ?string}|null
     */
    public function createCode(string $deviceId, string $name, CarbonInterface $startsAt, CarbonInterface $endsAt): ?array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post(self::BASE_URL.'/access_codes/create', [
            'device_id' => $deviceId,
            'name' => $name,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ]));

        $id = $response?->json('access_code.access_code_id');

        return is_string($id) && $id !== '' ? ['id' => $id, 'code' => $this->codeOf($response)] : null;
    }

    /** The digits of a code made earlier; null while the lock has not set them (yet). */
    public function code(string $accessCodeId): ?string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get(self::BASE_URL.'/access_codes/get', ['access_code_id' => $accessCodeId]));

        return $response === null ? null : $this->codeOf($response);
    }

    /** Takes the code off the lock. True as well when it was already gone. */
    public function removeCode(string $accessCodeId): bool
    {
        return $this->call(
            fn (PendingRequest $http) => $http->delete(self::BASE_URL.'/access_codes/delete?'.http_build_query(['access_code_id' => $accessCodeId])),
            goneIsFine: true,
        ) !== null;
    }

    private function codeOf(Response $response): ?string
    {
        $code = $response->json('access_code.code');

        return is_scalar($code) && (string) $code !== '' ? (string) $code : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function call(callable $request, bool $goneIsFine = false): ?Response
    {
        $key = $this->apiKey();

        if (blank($key)) {
            return null;
        }

        try {
            $response = $request(Http::withToken($key)->acceptJson()->timeout(20));
        } catch (ConnectionException $e) {
            Log::warning('Seam: no connection', ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->successful() || ($goneIsFine && $response->status() === 404)) {
            return $response;
        }

        Log::warning('Seam: request refused', [
            'status' => $response->status(),
            'error' => $response->json('error.type'),
            'request_id' => $response->header('seam-request-id'),
        ]);

        return null;
    }

    private function apiKey(): ?string
    {
        return Setting::get('locks', 'seam_api_key');
    }
}
