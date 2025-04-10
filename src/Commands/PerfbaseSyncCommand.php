<?php

namespace Perfbase\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Perfbase\Laravel\Caching\CacheStrategyFactory;
use Perfbase\SDK\Config;
use Perfbase\SDK\Http\ApiClient;
use RuntimeException;
use Throwable;

class PerfbaseSyncCommand extends Command
{
    /**
     * @var int The chunk size to avoid memory issues
     */
    private const CHUNK_SIZE = 100;
    /**
     * @var string The command signature
     */
    protected $signature = 'perfbase:sync';
    /**
     * @var string The command description
     */
    protected $description = 'Sync all cached/unsent profiles to Perfbase API';

    public function handle(): int
    {
        /** @var string $strategy */
        $strategy = config('perfbase.cache.config.enabled');

        if (!$strategy) {
            $this->error('Perfbase is not configured to use a cache strategy');
            return self::FAILURE;
        }

        $this->info(sprintf('Syncing profiles from %s to Perfbase API...', $strategy));

        // Begin transaction if using database strategy
        if ($strategy === 'database') {
            DB::connection($this->getConnectionName())->beginTransaction();
        }

        try {

            // Get the cache strategy
            $cache = CacheStrategyFactory::make();

            // Check for unsent profiles
            $this->info('Checking for unsent profiles...');
            $unsentCount = $cache->countUnsentProfiles();

            // If there are no unsent profiles, we can skip the sync
            if ($unsentCount === 0) {
                $this->info('No unsent profiles found, nothing to sync!');
                return self::SUCCESS;
            }

            $this->info(sprintf('Found %d unsent profiles, syncing...', $unsentCount));

            /** @var Application $app */
            $app = app();

            /**
             * Initialize the Perfbase SDK client
             * @var Config $config
             */
            $config = $app->make(Config::class);

            $client = new ApiClient($config);

            /**
             * IDs of profiles that have been synced.
             * We send in batches to avoid memory issues, so we need to keep track of the IDs.
             * @var array<int|string> $ids
             */
            $ids = [];

            try {
                // Grab a chunk of profiles from the cache and send them to Perfbase
                foreach ($cache->getUnsentProfiles(self::CHUNK_SIZE) as $profileChunk) {

                    // Foreach profile
                    foreach ($profileChunk as $profile) {

                        /** @var string $traceId */
                        $traceId = $profile['id'];
                        if (!is_string($traceId)) {
                            throw new RuntimeException(sprintf('Found invalid `id` for profile ID: %s', $traceId));
                        }

                        /** @var string $traceData */
                        $traceData = $profile['data'];
                        if (!is_array($traceData)) {
                            throw new RuntimeException(sprintf('Found invalid `data` for profile ID: %s', $traceId));
                        }

                        /** @var string $traceCreatedAt */
                        $traceCreatedAt = $profile['created_at'];
                        if (!strtotime($traceCreatedAt)) {
                            throw new RuntimeException(sprintf('Found invalid `created_at` timestamp for profile ID: %s', $traceId));
                        }

                        // Submit to the API
                        $client->submitTrace($traceData);

                        // Store the ID for deletion
                        $ids[] = $profile['id'];
                    }

                    // Delete the chunk of profiles from the cache and clear the IDs array
                    $cache->deleteMass($ids);

                    /** @var string $firstId */
                    $firstId = $ids[0];

                    /** @var string $lastId */
                    $lastId = $ids[count($ids) - 1];

                    $this->info(sprintf('Synced %d profiles, from profile %s to %s', count($ids), $firstId, $lastId));
                    $ids = [];

                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                if (!empty($ids)) {
                    $this->warn('An error occurred mid-sync, deleting the IDs that were synced');
                    $cache->deleteMass($ids);
                }
                throw new RuntimeException('Error occurred during sync, halting.');
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            // Finish up transaction if using database strategy
            if ($strategy === 'database') {
                // Finish up transaction
                DB::connection($this->getConnectionName())->commit();
            }
        }

        $this->info('Sync complete');

        return self::SUCCESS;
    }

    function getConnectionName(): string
    {
        $name = config('perfbase.cache.config.database.connection');
        if (!is_string($name)) {
            throw new RuntimeException('Invalid connection name');
        }
        return $name;
    }

}
