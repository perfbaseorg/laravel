<?php

namespace Perfbase\Laravel\Caching;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FileStrategy implements CacheStrategy
{
    /**
     * @var string The path where profile files are stored
     */
    private string $path;

    /**
     * @var string The file extension for profile files
     */
    private string $extension;

    /**
     * Initialize the file strategy with configured path and extension.
     */
    public function __construct()
    {
        $this->path = config('perfbase.connections.file.path');
        $this->extension = config('perfbase.connections.file.extension');
        
        if (!File::exists($this->path)) {
            File::makeDirectory($this->path, 0755, true);
        }
    }

    /**
     * Store a new profile as a file.
     *
     * @param array $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void
    {
        $filename = Str::uuid() . $this->extension;
        File::put($this->path . '/' . $filename, json_encode([
            'profile_data' => $profileData,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Get profiles from files that haven't been synced yet.
     *
     * @param int $limit Maximum number of profiles to retrieve
     * @return \Generator
     */
    public function getUnsyncedProfiles(int $limit = 100): iterable
    {
        $files = collect(File::files($this->path))
            ->filter(fn($file) => Str::endsWith($file->getFilename(), $this->extension))
            ->take($limit);

        foreach ($files as $file) {
            $content = json_decode(File::get($file), true);
            yield [
                'file' => $file,
                'profile_data' => $content['profile_data'],
                'created_at' => $content['created_at']
            ];
        }
    }

    /**
     * Delete a specific profile file.
     *
     * @param array $identifier The profile identifier containing the file path
     * @return void
     */
    public function delete(mixed $identifier): void
    {
        if (isset($identifier['file'])) {
            File::delete($identifier['file']);
        }
    }

    /**
     * Clear all profile files from the storage directory.
     *
     * @return void
     */
    public function clear(): void
    {
        collect(File::files($this->path))
            ->filter(fn($file) => Str::endsWith($file->getFilename(), $this->extension))
            ->each(fn($file) => File::delete($file));
    }
} 