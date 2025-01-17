<?php

namespace Perfbase\Laravel\Caching;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileInfo;

class FileStrategy implements CacheStrategy
{
    /**
     * The path where profile files are stored
     * @var string
     */
    private string $path;


    /**
     * The file extension for profile files
     * @var string
     */
    private string $extension = '.perfbase';

    /**
     * Initialize the file strategy with configured path and extension.
     */
    public function __construct()
    {
        $path = config('perfbase.connections.file.path');
        if (!is_string($path)) {
            throw new InvalidArgumentException('The file cache path must be a string');
        }
        $this->path = $path;
    }

    /**
     * Store a new profile as a file.
     *
     * @param array<string, mixed> $profileData The profile data to store
     * @return void
     */
    public function store(array $profileData): void
    {
        if (!File::exists($this->path)) {
            File::makeDirectory($this->path, 0755, true);
        }

        $filename = Str::uuid() . $this->extension;
        File::put($this->path . '/' . $filename, serialize([
            'id' => $filename,
            'data' => $profileData,
            'created_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Get profiles that haven't been synced yet.
     *
     * @param int $chunk Maximum number of profiles to retrieve at once
     * @return iterable<array<array<string,mixed>>>
     */
    public function getUnsentProfiles(int $chunk = 100): iterable
    {
        $files = collect(File::files($this->path));

        /** @var array<array<string>> $fileChunks */
        $fileChunks = $files->chunk($chunk);

        // Yield each profile in the chunk
        foreach ($fileChunks as $fileChunk) {

            /** @var array<array<string, string>> $yield */
            $yield = [];

            foreach ($fileChunk as $file) {

                /** @var array<string, mixed> $content */
                $content = unserialize(File::get($file));

                /** @var string $data - This will be json data */
                $data = $content['data'];

                /** @var string $created_at */
                $created_at = $content['created_at'];

                $yield[] = [
                    'id' => $file,
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
        return collect(File::files($this->path))
            ->filter(fn(SplFileInfo $file) => Str::endsWith($file->getFilename(), $this->extension))
            ->count();
    }

    /**
     * Delete multiple profiles from the cache.
     *
     * @param array<string> $ids
     * @return void
     */
    public function deleteMass(array $ids): void
    {
        foreach ($ids as $id) {
            $this->delete($id);
        }
    }

    /**
     * Delete a specific profile from the filesystem.
     *
     * @param string $id The file path
     * @return void
     */
    public function delete($id): void
    {
        $fullPath = $this->path . '/' . $id;
        if (File::exists($fullPath)) {
            File::delete($fullPath);
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
            ->filter(fn(SplFileInfo $file) => Str::endsWith($file->getFilename(), $this->extension))
            ->each(fn(SplFileInfo $file) => File::delete($file->getRealPath()));
    }
} 