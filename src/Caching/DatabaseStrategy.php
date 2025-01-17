<?php

namespace Perfbase\Laravel\Caching;

use Illuminate\Database\Eloquent\Collection;
use Perfbase\Laravel\Models\Profile;
use RuntimeException;

class DatabaseStrategy implements CacheStrategy
{
    /**
     * Store a new profile in the database.
     *
     * @param array<string, mixed> $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void
    {
        Profile::query()->create([
            'data' => $profileData
        ]);
    }

    /**
     * Get profiles that haven't been synced yet.
     *
     * @param int $chunk Maximum number of profiles to retrieve at once
     * @return iterable<array<array<string,mixed>>>
     */
    public function getUnsentProfiles(int $chunk = 100): iterable
    {
        $lastId = 0;
        while (true) {

            /** @var Collection<Profile> $profiles */
            $profiles = Profile::query()
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if (!count($profiles)) {
                break;
            }

            /** @var Profile $last */
            $last = $profiles->last();

            /** @var int $lastId */
            $lastId = $last->getKey();

            /** @var array<array<string, string>> $yield */
            $yield = [];

            /** @var Profile $profile */
            foreach($profiles as $profile) {

                /** @var int $id */
                $id = $profile->getAttribute('id');

                /** @var array<string, mixed> $data */
                $data = $profile->getAttribute('data');

                /** @var string $created_at */
                $created_at = $profile->getAttribute('created_at');


                $yield[] = [
                    'id' => $id,
                    'data' => $data,
                    'created_at' => $created_at
                ];
            }

            yield $yield;
        }
    }

    /**
     * Count the number of unsent profiles in the cache.
     *
     * @return int
     */
    public function countUnsentProfiles(): int
    {
        return Profile::query()->count();
    }

    /**
     * Delete multiple profiles from the cache.
     *
     * @param array<int> $ids
     * @return void
     */
    public function deleteMass(array $ids): void
    {
        Profile::query()->whereIn('id', $ids)
            ->delete();
    }

    /**
     * Delete a specific profile from the database.
     *
     * @param int $id The model id
     * @return void
     */
    public function delete($id): void
    {
        Profile::query()->where('id', $id)
            ->delete();
    }

    /**
     * Clear all profiles from the database.
     *
     * @return void
     */
    public function clear(): void
    {
        Profile::query()->truncate();
    }
} 