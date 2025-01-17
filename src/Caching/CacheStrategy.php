<?php

namespace Perfbase\Laravel\Caching;

interface CacheStrategy
{
    /**
     * Store a new profile in the cache.
     *
     * @param array<string, mixed> $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void;

    /**
     * Get profiles that haven't been synced yet.
     *
     * @param int $chunk Maximum number of profiles to retrieve at once
     * @return iterable<array<array<string, mixed>>>
     */
    public function getUnsentProfiles(int $chunk = 100): iterable;

    /**
     * Count the number of unsent profiles in the cache.
     *
     * @return int
     */
    public function countUnsentProfiles(): int;

    /**
     * Delete a specific profile from the cache.
     *
     * @param mixed $id The profile identifier
     * @return void
     */
    public function delete($id): void;

    /**
     * Delete multiple profiles from the cache.
     *
     * @param array<mixed> $ids
     * @return void
     */
    public function deleteMass(array $ids): void;

    /**
     * Clear all cached profiles.
     *
     * @return void
     */
    public function clear(): void;
} 