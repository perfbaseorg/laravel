<?php

namespace Perfbase\Laravel\Caching;

interface CacheStrategy
{
    /**
     * Store a new profile in the cache.
     *
     * @param array $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void;

    /**
     * Get profiles that haven't been synced yet.
     *
     * @param int $limit Maximum number of profiles to retrieve
     * @return iterable
     */
    public function getUnsyncedProfiles(int $limit = 100): iterable;

    /**
     * Delete a specific profile from the cache.
     *
     * @param mixed $identifier The profile identifier
     * @return void
     */
    public function delete(mixed $identifier): void;

    /**
     * Clear all cached profiles.
     *
     * @return void
     */
    public function clear(): void;
} 