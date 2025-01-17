<?php

namespace Perfbase\Laravel\Commands;

use Illuminate\Console\Command;
use Perfbase\Laravel\Caching\CacheStrategyFactory;

class PerfbaseClearCommand extends Command
{
    /**
     * @var string The command signature
     */
    protected $signature = 'perfbase:clear';

    /**
     * @var string The command description
     */
    protected $description = 'Deletes all unsent/cached profiles';

    /**
     * @return int The command exit code
     */
    public function handle(): int
    {
        $this->info('Clearing cached profiles...');
        $strategy = CacheStrategyFactory::make();
        $strategy->clear();
        $this->info('All cached profiles have been cleared.');
        return self::SUCCESS;
    }
}
