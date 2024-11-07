<?php

namespace Perfbase\Laravel\Caching;

use Perfbase\Laravel\Models\Profile;

class DatabaseStrategy implements CacheStrategy
{
    /**
     * Store a new profile in the database.
     *
     * @param array $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void
    {
        Profile::create([
            'profile_data' => $profileData,
            'created_at' => now(),
        ]);
    }

    /**
     * Get profiles that haven't been synced yet from the database.
     *
     * @param int $limit Maximum number of profiles to retrieve
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUnsyncedProfiles(int $limit = 100): iterable
    {
        return Profile::take($limit)->get();
    }

    /**
     * Delete a specific profile from the database.
     *
     * @param Profile $identifier The profile model instance
     * @return void
     */
    public function delete(mixed $identifier): void
    {
        if ($identifier instanceof Profile) {
            $identifier->delete();
        }
    }

    /**
     * Clear all profiles from the database.
     *
     * @return void
     */
    public function clear(): void
    {
        Profile::truncate();
    }
} 