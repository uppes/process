<?php

declare(strict_types=1);

/**
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Async\Processor;

use Throwable;
use Async\Processor\Process;
use Async\Processor\ProcessorError;
use Async\Processor\SerializableException;
use Async\Processor\LauncherInterface;

/**
 * Launcher runs a command/script/application/callable in an independent process.
 */
class Launcher implements LauncherInterface
{
    protected $timeout = null;
    protected $process;
    protected $id;
    protected $pid;

    protected $output;
    protected $errorOutput;
    protected $realOutput;
    protected $realTimeType;
    protected $realTimeOutput;

    protected $startTime;
    protected $showOutput = false;

    protected $successCallbacks = [];
    protected $errorCallbacks = [];
    protected $timeoutCallbacks = [];
    protected $progressCallbacks = [];

    private function __construct(Process $process, int $id, int $timeout = 300)
    {
        $this->timeout = $timeout;
        $this->process = $process;
        $this->id = $id;
    }

    public static function create(Process $process, int $id, int $timeout = 300): LauncherInterface
    {
        return new self($process, $id, $timeout);
    }

    public function start(): LauncherInterface
    {
        $this->startTime = \microtime(true);

        $this->process->start(function ($type, $buffer) {
            $this->realTimeType = $type;
            $this->realTimeOutput .= $buffer;
            $this->display($buffer);
            $this->triggerOutput();
        });

        $this->pid = $this->process->getPid();

        return $this;
    }

    public function restart(): LauncherInterface
    {
        if ($this->isRunning())
            $this->stop();

        $process = clone $this->process;

        $launcher = $this->create($process, $this->id, $this->timeout);

        return $launcher->start();
    }

    public function run(bool $useYield = false)
    {
        $this->start();

        if ($useYield)
            return $this->wait(1000, true);

        return $this->wait();
    }

    public function yielding()
    {
        return yield from $this->run(true);
    }

    public function display($buffer = null)
    {
        if ($this->showOutput) {
            \printf('%s', $this->realTime($buffer));
        }
    }

    public function wait($waitTimer = 1000, bool $useYield = false)
    {
        while ($this->isRunning()) {
            if ($this->isTimedOut()) {
                $this->stop();
                if ($useYield)
                    return $this->yieldTimeout();

                return $this->triggerTimeout();
            }

            \usleep($waitTimer);
        }

        return $this->checkProcess($useYield);
    }

    protected function checkProcess(bool $useYield = false)
    {
        if ($this->isSuccessful()) {
            if ($useYield)
                return $this->yieldSuccess();

            return $this->triggerSuccess();
        }

        if ($useYield)
            return $this->yieldError();

        return $this->triggerError();
    }

    public function stop(): LauncherInterface
    {
        $this->process->stop();

        return $this;
    }

    public function isTimedOut(): bool
    {
        if (empty($this->timeout) || !$this->process->isStarted()) {
            return false;
        }

        return ((\microtime(true) - $this->startTime) > $this->timeout);
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function displayOn(): LauncherInterface
    {
        $this->showOutput = true;

        return $this;
    }

    public function isSuccessful(): bool
    {
        return $this->process->isSuccessful();
    }

    public function isTerminated(): bool
    {
        return $this->process->isTerminated();
    }

    public function cleanUp($output = null)
    {
        return \is_string($output)
                ? \str_replace('Tjs=', '', $output)
                : $output;
    }

    public function getOutput()
    {
        if (!$this->output) {
            $processOutput = $this->process->getOutput();

            $this->output = @\unserialize(\base64_decode((string) $processOutput));

            if (!$this->output) {
                $this->errorOutput = $this->cleanUp($processOutput);
            }

            $this->output = $this->cleanUp($this->output);
        }

        return $this->output;
    }

    public function getRealOutput()
    {
        if (!$this->realOutput) {
            $processOutput = $this->realTimeOutput;

            $this->realTimeOutput = null;
            $this->realOutput = @\unserialize(\base64_decode((string) $processOutput));
            if (!$this->realOutput) {
                $this->realOutput = $this->cleanUp($processOutput);
            } else {
                $this->realOutput = $this->cleanUp($this->realOutput);
            }
        }

        return $this->realOutput;
    }

    protected function realTime($buffer = null)
    {
        if (!empty($buffer)) {
            $processOutput = $buffer;
            $realOutput = @\unserialize(\base64_decode($processOutput));
            if (!$realOutput) {
                $realOutput = $processOutput;
            }

            $realOutput = $this->cleanUp($realOutput);
            $this->realOutput = null;

            return $realOutput;
        }
    }

    public function getErrorOutput()
    {
        if (!$this->errorOutput) {
            $processOutput = $this->process->getErrorOutput();

            $this->errorOutput = @\unserialize(\base64_decode((string) $processOutput));

            if (!$this->errorOutput) {
                $this->errorOutput = $processOutput;
            }
        }

        return $this->errorOutput;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function then(callable $doneCallback, callable $failCallback = null, callable $progressCallback = null): LauncherInterface
    {
        $this->done($doneCallback);

        if ($failCallback !== null) {
            $this->catch($failCallback);
        }

        if ($progressCallback !== null) {
            $this->progress($progressCallback);
        }

        return $this;
    }

    public function progress(callable $progressCallback): LauncherInterface
    {
        $this->progressCallbacks[] = $progressCallback;

        return $this;
    }

    public function done(callable $callback): LauncherInterface
    {
        $this->successCallbacks[] = $callback;

        return $this;
    }

    public function catch(callable $callback): LauncherInterface
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    public function timeout(callable $callback): LauncherInterface
    {
        $this->timeoutCallbacks[] = $callback;

        return $this;
    }

    public function triggerOutput()
    {
        if (\count($this->progressCallbacks) > 0) {
            $liveType = $this->realTimeType;
            $liveOutput = $this->realTime($this->realTimeOutput);
            foreach ($this->progressCallbacks as $progressCallback) {
                $progressCallback($liveType, $liveOutput);
                $this->realOutput = null;
            }
        }
    }

    public function triggerSuccess()
    {
        if ($this->getRealOutput() && !$this->getErrorOutput()) {
            $output = $this->realOutput;
            $this->output = $output;
        } elseif ($this->getErrorOutput()) {
            return $this->triggerError();
        } else {
            $output = $this->getOutput();
            $output = !empty($this->output) ? $output : $this->getRealOutput();
        }

        foreach ($this->successCallbacks as $callback)
            $callback($output);

        return $output;
    }

    public function triggerError()
    {
        $exception = $this->resolveErrorOutput();

        foreach ($this->errorCallbacks as $callback)
            $callback($exception);

        if (!$this->errorCallbacks) {
            throw $exception;
        }
    }

    public function triggerTimeout()
    {
        foreach ($this->timeoutCallbacks as $callback)
            $callback();
    }

    public function yieldSuccess()
    {
        if ($this->getErrorOutput()) {
            return $this->yieldError();
        } else {
            $output = $this->getOutput();
            $output = empty($this->output) ? $this->getRealOutput() : $output;
        }

        foreach ($this->successCallbacks as $callback) {
            yield $callback($output);
        }

        return $output;
    }

    public function yieldError()
    {
        $exception = $this->resolveErrorOutput();

        foreach ($this->errorCallbacks as $callback) {
            yield $callback($exception);
        }

        if (!$this->errorCallbacks) {
            throw $exception;
        }
    }

    public function yieldTimeout()
    {
        foreach ($this->timeoutCallbacks as $callback) {
            yield $callback();
        }
    }

    protected function resolveErrorOutput(): Throwable
    {
        $exception = $this->getErrorOutput();

        if ($exception instanceof SerializableException) {
            $exception = $exception->asThrowable();
        }

        if (!$exception instanceof Throwable) {
            $exception = ProcessorError::fromException($exception);
        }

        return $exception;
    }
}
