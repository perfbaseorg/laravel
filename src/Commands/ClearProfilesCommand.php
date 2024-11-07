<?php

namespace Perfbase\Laravel\Commands;

use Illuminate\Console\Command;
use Perfbase\Laravel\Caching\CacheStrategyFactory;

class ClearProfilesCommand extends Command
{
    /**
     * @var string The command signature
     */
    protected $signature = 'perfbase:clear';

    /**
     * @var string The command description
     */
    protected $description = 'Clear all cached profiles';

    /**
     * @return int The command exit code
     */
    public function handle(): int
    {
        $strategy = CacheStrategyFactory::make();

        if (!$strategy) {
            $this->error('No cache strategy configured.');
            return self::FAILURE;
        }

        $strategy->clear();
        $this->info('All cached profiles have been cleared.');

        return self::SUCCESS;
    }
}
