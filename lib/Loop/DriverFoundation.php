<?php

namespace Amp\Loop;

use Amp\Promise;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Watchers (enabled or new watchers) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
 *
 * All registered callbacks MUST NOT be called from a file with strict types enabled (`declare(strict_types=1)`).
 */
abstract class DriverFoundation implements Driver
{
    // Don't use 1e3 / 1e6, they result in a float instead of int
    protected const MILLISEC_PER_SEC = 1000;
    protected const MICROSEC_PER_SEC = 1000000;

    /** @var string Next watcher ID. */
    private string $nextId = "a";

    /** @var Watcher[] */
    private array $watchers = [];

    /** @var Watcher[] */
    private array $enableQueue = [];

    /** @var Watcher[] */
    private array $deferQueue = [];

    /** @var Watcher[] */
    private array $nextTickQueue = [];

    /** @var callable(\Throwable):void|null */
    private $errorHandler = null;

    /** @var mixed[] */
    private array $registry = [];

    private bool $running = false;

    private \FiberScheduler $scheduler;

    /**
     * Get the fiber scheduler associated with this driver.
     *
     * @return \FiberScheduler
     */
    public function getScheduler(): \FiberScheduler
    {
        if (!isset($this->scheduler) || $this->scheduler->isTerminated()) {
            $this->scheduler = new \FiberScheduler(fn() => $this->run());
        }

        return $this->scheduler;
    }

    /**
     * Run the event loop.
     *
     * One iteration of the loop is called one "tick". A tick covers the following steps:
     *
     *  1. Activate watchers created / enabled in the last tick / before `run()`.
     *  2. Execute all enabled defer watchers.
     *  3. Execute all due timer, pending signal and actionable stream callbacks, each only once per tick.
     *
     * The loop MUST continue to run until it is either stopped explicitly, no referenced watchers exist anymore, or an
     * exception is thrown that cannot be handled. Exceptions that cannot be handled are exceptions thrown from an
     * error handler or exceptions that would be passed to an error handler but none exists to handle them.
     *
     * @return void
     *
     * @throw \Error Thrown if the event loop is already running.
     */
    public function run(): void
    {
        if ($this->running) {
            throw new \Error("The loop was already running");
        }

        $this->running = true;

        try {
            while ($this->running) {
                if ($this->isEmpty()) {
                    return;
                }

                $this->tick();
            }
        } finally {
            $this->stop();
        }
    }

    /**
     * Stop the event loop.
     *
     * When an event loop is stopped, it continues with its current tick and exits the loop afterwards. Multiple calls
     * to stop MUST be ignored and MUST NOT raise an exception.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @return bool True if the event loop is running, false if it is stopped.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callable MUST be executed before any other type of watcher in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param callable (string $watcherId, mixed $data) $callback The callback to defer. The `$watcherId` will be
     *     invalidated before the callback call.
     * @param mixed $data Arbitrary data given to the callback function as the `$data` parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function defer(callable $callback, $data = null): string
    {
        /** @psalm-var Watcher<null> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::DEFER;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->nextTickQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param int   $delay The amount of time, in milliseconds, to delay the execution for.
     * @param callable (string $watcherId, mixed $data) $callback The callback to delay. The `$watcherId` will be
     *     invalidated before the callback call.
     * @param mixed $data Arbitrary data given to the callback function as the `$data` parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function delay(int $delay, callable $callback, $data = null): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        /** @psalm-var Watcher<int> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::DELAY;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->value = $delay;
        $watcher->expiration = $this->now() + $delay;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Repeatedly execute a callback.
     *
     * The interval between executions is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be
     * determined by which timers expire first, but timers with the same expiration time MAY be executed in any order.
     * The first execution is scheduled after the first interval period.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param int   $interval The time interval, in milliseconds, to wait between executions.
     * @param callable (string $watcherId, mixed $data) $callback The callback to repeat.
     * @param mixed $data Arbitrary data given to the callback function as the `$data` parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function repeat(int $interval, callable $callback, $data = null): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        /** @psalm-var Watcher<int> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::REPEAT;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->value = $interval;
        $watcher->expiration = $this->now() + $interval;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * watcher when closing the resource locally. Drivers MAY choose to notify the user if there are watchers on invalid
     * resources, but are not required to, due to the high performance impact. Watchers on closed resources are
     * therefore undefined behavior.
     *
     * Multiple watchers on the same stream MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param callable (string $watcherId, resource $stream, mixed $data) $callback The callback to execute.
     * @param mixed    $data Arbitrary data given to the callback function as the `$data` parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function onReadable($stream, callable $callback, $data = null): string
    {
        /** @psalm-var Watcher<resource> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::READABLE;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->value = $stream;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * watcher when closing the resource locally. Drivers MAY choose to notify the user if there are watchers on invalid
     * resources, but are not required to, due to the high performance impact. Watchers on closed resources are
     * therefore undefined behavior.
     *
     * Multiple watchers on the same stream MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param resource $stream The stream to monitor.
     * @param callable (string $watcherId, resource $stream, mixed $data) $callback The callback to execute.
     * @param mixed    $data Arbitrary data given to the callback function as the `$data` parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     */
    public function onWritable($stream, callable $callback, $data = null): string
    {
        /** @psalm-var Watcher<resource> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::WRITABLE;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->value = $stream;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Execute a callback when a signal is received.
     *
     * Warning: Installing the same signal on different instances of this interface is deemed undefined behavior.
     * Implementations MAY try to detect this, if possible, but are not required to. This is due to technical
     * limitations of the signals being registered globally per process.
     *
     * Multiple watchers on the same signal MAY be executed in any order.
     *
     * The created watcher MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param int   $signo The signal number to monitor.
     * @param callable (string $watcherId, int $signo, mixed $data) $callback The callback to execute.
     * @param mixed $data Arbitrary data given to the callback function as the $data parameter.
     *
     * @return string An unique identifier that can be used to cancel, enable or disable the watcher.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signo, callable $callback, $data = null): string
    {
        /** @psalm-var Watcher<int> $watcher */
        $watcher = new Watcher;
        $watcher->type = Watcher::SIGNAL;
        $watcher->id = $this->nextId++;
        $watcher->callback = $callback;
        $watcher->value = $signo;
        $watcher->data = $data;

        $this->watchers[$watcher->id] = $watcher;
        $this->enableQueue[$watcher->id] = $watcher;

        return $watcher->id;
    }

    /**
     * Enable a watcher to be active starting in the next tick.
     *
     * Watchers MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right before
     * the next tick. Callbacks of watchers MUST NOT be called in the tick they were enabled.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return void
     *
     * @throws InvalidWatcherError If the watcher identifier is invalid.
     */
    public function enable(string $watcherId): void
    {
        if (!isset($this->watchers[$watcherId])) {
            throw new InvalidWatcherError($watcherId, "Cannot enable an invalid watcher identifier: '{$watcherId}'");
        }

        $watcher = $this->watchers[$watcherId];

        if ($watcher->enabled) {
            return; // Watcher already enabled.
        }

        $watcher->enabled = true;

        switch ($watcher->type) {
            case Watcher::DEFER:
                $this->nextTickQueue[$watcher->id] = $watcher;
                break;

            case Watcher::REPEAT:
            case Watcher::DELAY:
                \assert(\is_int($watcher->value));

                $watcher->expiration = $this->now() + $watcher->value;
                $this->enableQueue[$watcher->id] = $watcher;
                break;

            default:
                $this->enableQueue[$watcher->id] = $watcher;
                break;
        }
    }

    /**
     * Cancel a watcher.
     *
     * This will detach the event loop from all resources that are associated to the watcher. After this operation the
     * watcher is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid watcher.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return void
     */
    public function cancel(string $watcherId): void
    {
        $this->disable($watcherId);
        unset($this->watchers[$watcherId]);
    }

    /**
     * Disable a watcher immediately.
     *
     * A watcher MUST be disabled immediately, e.g. if a defer watcher disables a later defer watcher, the second defer
     * watcher isn't executed in this tick.
     *
     * Disabling a watcher MUST NOT invalidate the watcher. Calling this function MUST NOT fail, even if passed an
     * invalid watcher.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return void
     */
    public function disable(string $watcherId): void
    {
        if (!isset($this->watchers[$watcherId])) {
            return;
        }

        $watcher = $this->watchers[$watcherId];

        if (!$watcher->enabled) {
            return; // Watcher already disabled.
        }

        $watcher->enabled = false;
        $id = $watcher->id;

        switch ($watcher->type) {
            case Watcher::DEFER:
                if (isset($this->nextTickQueue[$id])) {
                    // Watcher was only queued to be enabled.
                    unset($this->nextTickQueue[$id]);
                } else {
                    unset($this->deferQueue[$id]);
                }
                break;

            default:
                if (isset($this->enableQueue[$id])) {
                    // Watcher was only queued to be enabled.
                    unset($this->enableQueue[$id]);
                } else {
                    $this->deactivate($watcher);
                }
                break;
        }
    }

    /**
     * Reference a watcher.
     *
     * This will keep the event loop alive whilst the watcher is still being monitored. Watchers have this state by
     * default.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return void
     *
     * @throws InvalidWatcherError If the watcher identifier is invalid.
     */
    public function reference(string $watcherId): void
    {
        if (!isset($this->watchers[$watcherId])) {
            throw new InvalidWatcherError($watcherId, "Cannot reference an invalid watcher identifier: '{$watcherId}'");
        }

        $this->watchers[$watcherId]->referenced = true;
    }

    /**
     * Unreference a watcher.
     *
     * The event loop should exit the run method when only unreferenced watchers are still being monitored. Watchers
     * are all referenced by default.
     *
     * @param string $watcherId The watcher identifier.
     *
     * @return void
     */
    public function unreference(string $watcherId): void
    {
        if (!isset($this->watchers[$watcherId])) {
            return;
        }

        $this->watchers[$watcherId]->referenced = false;
    }

    /**
     * Stores information in the loop bound registry.
     *
     * Stored information is package private. Packages MUST NOT retrieve the stored state of other packages. Packages
     * MUST use their namespace as prefix for keys. They may do so by using `SomeClass::class` as key.
     *
     * If packages want to expose loop bound state to consumers other than the package, they SHOULD provide a dedicated
     * interface for that purpose instead of sharing the storage key.
     *
     * @param string $key The namespaced storage key.
     * @param mixed  $value The value to be stored.
     *
     * @return void
     */
    final public function setState(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->registry[$key]);
        } else {
            $this->registry[$key] = $value;
        }
    }

    /**
     * Gets information stored bound to the loop.
     *
     * Stored information is package private. Packages MUST NOT retrieve the stored state of other packages. Packages
     * MUST use their namespace as prefix for keys. They may do so by using `SomeClass::class` as key.
     *
     * If packages want to expose loop bound state to consumers other than the package, they SHOULD provide a dedicated
     * interface for that purpose instead of sharing the storage key.
     *
     * @param string $key The namespaced storage key.
     *
     * @return mixed The previously stored value or `null` if it doesn't exist.
     */
    final public function getState(string $key): mixed
    {
        return isset($this->registry[$key]) ? $this->registry[$key] : null;
    }

    /**
     * Set a callback to be executed when an error occurs.
     *
     * The callback receives the error as the first and only parameter. The return value of the callback gets ignored.
     * If it can't handle the error, it MUST throw the error. Errors thrown by the callback or during its invocation
     * MUST be thrown into the `run` loop and stop the driver.
     *
     * Subsequent calls to this method will overwrite the previous handler.
     *
     * @param callable(\Throwable $error):void|null $callback The callback to execute. `null` will clear the
     *     current handler.
     *
     * @return callable(\Throwable $error):void|null The previous handler, `null` if there was none.
     */
    public function setErrorHandler(callable $callback = null): ?callable
    {
        $previous = $this->errorHandler;
        $this->errorHandler = $callback;
        return $previous;
    }

    /**
     * Returns the current loop time in millisecond increments. Note this value does not necessarily correlate to
     * wall-clock time, rather the value returned is meant to be used in relative comparisons to prior values returned
     * by this method (intervals, expiration calculations, etc.) and is only updated once per loop tick.
     *
     * Extending classes should override this function to return a value cached once per loop tick.
     *
     * @return int
     */
    public function now(): int
    {
        return (int) (\microtime(true) * self::MILLISEC_PER_SEC);
    }

    /**
     * Returns the same array of data as getInfo().
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return $this->getInfo();
        // @codeCoverageIgnoreEnd
    }

    /**
     * Retrieve an associative array of information about the event loop driver.
     *
     * The returned array MUST contain the following data describing the driver's currently registered watchers:
     *
     *     [
     *         "defer"            => ["enabled" => int, "disabled" => int],
     *         "delay"            => ["enabled" => int, "disabled" => int],
     *         "repeat"           => ["enabled" => int, "disabled" => int],
     *         "on_readable"      => ["enabled" => int, "disabled" => int],
     *         "on_writable"      => ["enabled" => int, "disabled" => int],
     *         "on_signal"        => ["enabled" => int, "disabled" => int],
     *         "enabled_watchers" => ["referenced" => int, "unreferenced" => int],
     *     ];
     *
     * Implementations MAY optionally add more information in the array but at minimum the above `key => value` format
     * MUST always be provided.
     *
     * @return array Statistics about the loop in the described format.
     */
    public function getInfo(): array
    {
        $watchers = [
            "referenced" => 0,
            "unreferenced" => 0,
        ];

        $defer = $delay = $repeat = $onReadable = $onWritable = $onSignal = [
            "enabled" => 0,
            "disabled" => 0,
        ];

        foreach ($this->watchers as $watcher) {
            switch ($watcher->type) {
                case Watcher::READABLE:
                    $array = &$onReadable;
                    break;
                case Watcher::WRITABLE:
                    $array = &$onWritable;
                    break;
                case Watcher::SIGNAL:
                    $array = &$onSignal;
                    break;
                case Watcher::DEFER:
                    $array = &$defer;
                    break;
                case Watcher::DELAY:
                    $array = &$delay;
                    break;
                case Watcher::REPEAT:
                    $array = &$repeat;
                    break;

                default:
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown watcher type");
                // @codeCoverageIgnoreEnd
            }

            if ($watcher->enabled) {
                ++$array["enabled"];

                if ($watcher->referenced) {
                    ++$watchers["referenced"];
                } else {
                    ++$watchers["unreferenced"];
                }
            } else {
                ++$array["disabled"];
            }
        }

        return [
            "enabled_watchers" => $watchers,
            "defer" => $defer,
            "delay" => $delay,
            "repeat" => $repeat,
            "on_readable" => $onReadable,
            "on_writable" => $onWritable,
            "on_signal" => $onSignal,
        ];
    }

    /**
     * Removes all watchers, registry data, and error handler from the event loop. This method is intended for
     * clearing the loop between tests and not intended for use in an application.
     */
    final public function clear(): void
    {
        foreach ($this->watchers as $watcher) {
            $this->cancel($watcher->id);
        }

        $this->registry = [];
        $this->errorHandler = null;
    }

    /**
     * Activates (enables) all the given watchers.
     *
     * @param Watcher[] $watchers
     *
     * @return void
     */
    abstract protected function activate(array $watchers): void;

    /**
     * Dispatches any pending read/write, timer, and signal events.
     *
     * @param bool $blocking
     *
     * @return void
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Deactivates (disables) the given watcher.
     *
     * @param Watcher $watcher
     *
     * @return void
     */
    abstract protected function deactivate(Watcher $watcher): void;

    /**
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from a watcher callback.
     *
     * @return void
     * @throws \Throwable If no error handler has been set.
     */
    protected function error(\Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            throw $exception;
        }

        ($this->errorHandler)($exception);
    }

    /**
     * @return bool True if no enabled and referenced watchers remain in the loop.
     */
    private function isEmpty(): bool
    {
        foreach ($this->watchers as $watcher) {
            if ($watcher->enabled && $watcher->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * Executes a single tick of the event loop.
     *
     * @return void
     */
    private function tick(): void
    {
        if (empty($this->deferQueue)) {
            $this->deferQueue = $this->nextTickQueue;
        } else {
            $this->deferQueue = \array_merge($this->deferQueue, $this->nextTickQueue);
        }
        $this->nextTickQueue = [];

        $this->activate($this->enableQueue);
        $this->enableQueue = [];

        foreach ($this->deferQueue as $watcher) {
            if (!isset($this->deferQueue[$watcher->id])) {
                continue; // Watcher disabled by another defer watcher.
            }

            unset($this->watchers[$watcher->id], $this->deferQueue[$watcher->id]);

            try {
                /** @var mixed $result */
                $result = ($watcher->callback)($watcher->id, $watcher->data);

                if ($result instanceof Promise) {
                    Promise\rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch(
            empty($this->nextTickQueue)
            && empty($this->enableQueue)
            && $this->running
            && !$this->isEmpty()
        );
    }
}