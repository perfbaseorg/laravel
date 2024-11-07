<?php

namespace Perfbase\Laravel\Commands;

use Illuminate\Console\Command;
use Perfbase\Laravel\Models\Profile;
use Perfbase\SDK\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncProfilesCommand extends Command
{
    /**
     * @var string The command signature
     */
    protected $signature = 'perfbase:sync-profiles';

    /**
     * @var string The command description
     */
    protected $description = 'Sync cached profiles to Perfbase API';

    /**
     * @var int The chunk size to avoid memory issues
     */
    private const CHUNK_SIZE = 100;

    /**
     * @param Client $client The Perfbase client instance
     * @return int The command exit code
     */
    public function handle(Client $client): int
    {
        $this->info('Syncing profiles...');

        // Start transaction
        DB::connection(config('perfbase.database.connection'))->transaction(function () use ($client) {

            /** 
             * Get profiles from local database, cursor is used to avoid memory issues
             * @var Collection<Profile> $profiles 
             */
            Profile::query()->select('id', 'data')->cursor()->chunk(self::CHUNK_SIZE, function (Collection $profiles) use ($client) {

                /** 
                 * Get ids from local database
                 * @var Collection<int> $ids 
                 */
                $ids = $profiles->pluck('id')->toArray();

                /**
                 * Get data from local database
                 * @var Collection<array> $data
                 */
                $data = $profiles->pluck('data')->toArray();

                // Send data to Perfbase API
                $client->sendProfilingDataBulk($data);

                // Delete profiles from local database
                Profile::query()->whereIn('id', $ids)->delete();

                $this->info(sprintf('Synced %d profiles', $profiles->count()));
            });
        });

        $this->info('Done!');

        return self::SUCCESS;
    }
}
