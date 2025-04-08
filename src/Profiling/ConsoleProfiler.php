<?php

namespace Perfbase\Laravel\Profiling;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Perfbase\SDK\Exception\PerfbaseException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ConsoleProfiler
 *
 * Handles Console profiling using the Perfbase SDK.
 */
class ConsoleProfiler extends AbstractProfiler
{
    /** @var Command|string */
    private $command;

    /** @var InputInterface */
    private InputInterface $input;

    /** @var OutputInterface */
    private OutputInterface $output;

    /**
     * @param Command|string $command
     * @throws BindingResolutionException
     */
    public function __construct($command, InputInterface $input, OutputInterface $output)
    {
        parent::__construct(app(), 'console');
        $this->command = $command;
        $this->input = $input;
        $this->output = $output;
    }

    public function setExitCode(int $exitCode): void
    {
        $this->setAttribute('exit_code', (string)$exitCode);
    }

    /**
     * Check to see if we should profile the command.
     *
     * @return bool
     * @throws PerfbaseException
     */
    protected function shouldProfile(): bool
    {
        if (!config('perfbase.enabled', false)) {
            return false;
        }

        $commandName = $this->getCommandName();

        $includes = config('perfbase.include.console', []);
        if (!is_array($includes)) {
            throw new PerfbaseException('Configured perfbase console `includes` must be an array.');
        }

        if (!empty($includes) && !in_array($commandName, $includes, true)) {
            return false;
        }

        $excludes = config('perfbase.exclude.console', []);
        if (!is_array($excludes)) {
            throw new PerfbaseException('Configured perfbase console `excludes` must be an array.');
        }

        if (!empty($excludes) && in_array($commandName, $excludes, true)) {
            return false;
        }

        return true;
    }

    /**
     * Set the default attributes for the console trace.
     *
     * @return void
     * @throws PerfbaseException
     */
    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        // Add console specific attributes
        $this->setAttributes([
            'command' => $this->getCommandName(),
            'arguments' => json_encode($this->input->getArguments()) ?: '',
            'options' => json_encode($this->input->getOptions()) ?: '',
            'verbosity' => $this->getVerbosityLevel(),
        ]);
    }

    /**
     * Return the name of the command being run.
     *
     * @return string
     */
    private function getCommandName(): string
    {
        if ($this->command instanceof Command) {
            return $this->command->getName() ?? '';
        }
        return $this->command;
    }

    /**
     * Return the verbosity level of the command.
     *
     * @return string
     */
    private function getVerbosityLevel(): string
    {
        $verbosity = $this->output->getVerbosity();
        switch ($verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                return 'quiet';
            case OutputInterface::VERBOSITY_NORMAL:
                return 'normal';
            case OutputInterface::VERBOSITY_VERBOSE:
                return 'verbose';
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                return 'very_verbose';
            case OutputInterface::VERBOSITY_DEBUG:
                return 'debug';
            default:
                return 'unknown';
        }
    }
}
