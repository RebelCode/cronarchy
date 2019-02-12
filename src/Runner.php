<?php

namespace RebelCode\Cronarchy;

/**
 * The runner class.
 *
 * This class is responsible for running the daemon script, as well managing its state and configuration.
 *
 * @since [*next-version*]
 */
class Runner
{
    /**
     * State identifier for when the daemon is not running.
     *
     * @since [*next-version*]
     */
    const STATE_STOPPED = 0;

    /**
     * State identifier for when the daemon is idle (waiting to ping itself).
     *
     * @since [*next-version*]
     */
    const STATE_IDLE = 1;

    /**
     * State identifier for when the daemon is queued to run.
     *
     * @since [*next-version*]
     */
    const STATE_QUEUED = 2;

    /**
     * State identifier for when the daemon is preparing to run pending jobs.
     *
     * @since [*next-version*]
     */
    const STATE_PREPARING = 3;

    /**
     * State identifier for when the daemon is running pending jobs.
     *
     * @since [*next-version*]
     */
    const STATE_RUNNING = 4;

    /**
     * The suffix of the name of the option where the daemon's state is stored.
     *
     * @since [*next-version*]
     */
    const STATE_OPTION_SUFFIX = 'cronarchy_state';

    /**
     * The suffix of the name of the option where the timestamp for the last state change is stored.
     *
     * @since [*next-version*]
     */
    const LAST_STATE_CHANGE_OPTION_SUFFIX = 'cronarchy_last_state_change';

    /**
     * The suffix of the name of the option where the daemon's last run timestamp is stored.
     *
     * @since [*next-version*]
     */
    const LAST_RUN_OPTION_SUFFIX = 'cronarchy_last_run';

    /**
     * The absolute URL to the daemon script.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $daemonUrl;

    /**
     * The prefix to use for options in the wp_options table.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $optionPrefix;

    /**
     * The maximum amount of time, in seconds, that need to elapse before the daemon can run again.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $runInterval;

    /**
     * The maximum amount of time, in seconds, that the daemon is allowed to run for before it is considered to have
     * erred or be stuck.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $maxRunTime;

    /**
     * Whether or not the daemon pings itself.
     *
     * This is an EXPERIMENTAL feature!
     *
     * @since [*next-version*]
     *
     * @var bool
     */
    protected $pingSelf;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $daemonUrl    The absolute URL to the daemon script.
     * @param string $optionPrefix The prefix to use for options in the wp_options table.
     * @param int    $runInterval  The maximum amount of time, in seconds, that need to elapse before the daemon can
     *                             run again.
     * @param int    $maxRunTime   The maximum amount of time, in seconds, that the daemon is allowed to run for
     *                             before it is considered to have erred or be stuck.
     * @param bool   $pingSelf     Whether or not the daemon pings itself.. This is an EXPERIMENTAL feature!
     */
    public function __construct(
        $daemonUrl,
        $optionPrefix = '',
        $runInterval = 10,
        $maxRunTime = 600,
        $pingSelf = false
    ) {
        $this->daemonUrl    = $daemonUrl;
        $this->optionPrefix = $optionPrefix;
        $this->runInterval  = $runInterval;
        $this->maxRunTime   = $maxRunTime;
        $this->pingSelf     = $pingSelf;
    }

    /**
     * Retrieves the state of the runner.
     *
     * @since [*next-version*]
     *
     * @return int
     */
    public function getState()
    {
        return intval(get_option($this->getStateOptionName(), 0));
    }

    /**
     * Retrieves the timestamp for when the last state change occurred.
     *
     * @since [*next-version*]
     *
     * @return int
     */
    public function getLastStateChangeTime()
    {
        return intval(get_option($this->getLastStateChangeOptionName(), time()));
    }

    /**
     * Sets the state of the runner.
     *
     * @since [*next-version*]
     *
     * @param int $state An integer state. See the "STATE_*" constants.
     */
    public function setState($state)
    {
        update_option($this->getStateOptionName(), intval($state));
        update_option($this->getLastStateChangeOptionName(), intval($state));
    }

    /**
     * Retrieves the maximum amount of time, in seconds, that need to elapse before the daemon can run again.
     *
     * @since [*next-version*]
     *
     * @return int
     */
    public function getRunInterval()
    {
        return $this->runInterval;
    }

    /**
     * Retrieves the maximum amount of time, in seconds, that the daemon is allowed to run for before it is
     * considered to have erred or be stuck.
     *
     * @since [*next-version*]
     *
     * @return int
     */
    public function getMaxRunTime()
    {
        return $this->maxRunTime;
    }

    /**
     * Retrieves the timestamp for when the daemon was last run.
     *
     * @since [*next-version*]
     *
     * @return int|null The timestamp, or null if the daemon has not yet been run.
     */
    public function getLastRunTime()
    {
        return intval(get_option($this->getLastRunOptionName(), 0));
    }

    /**
     * Sets the timestamp for when the daemon was last run.
     *
     * @since [*next-version*]
     *
     * @param int|null $time Optional timestamp. If omitted or null, the current timestamp is used.
     */
    public function setLastRunTime($time = null)
    {
        $value = ($time === null) ? time() : intval($time);

        update_option($this->getLastRunOptionName(), $value);
    }

    /**
     * Returns whether or not the daemon pings itself.
     *
     * This is an EXPERIMENTAL feature!
     *
     * @since [*next-version*]
     *
     * @return bool True if multi-threaded, false if not.
     */
    public function isSelfPinging()
    {
        return $this->pingSelf;
    }

    /**
     * Checks if the daemon can be run.
     *
     * The daemon can run if its not already running or if its not too soon to run again,
     * or if its been running for too long, in which case we assume that it erred and never
     * cleaned up its state.
     *
     * @since [*next-version*]
     *
     * @return bool True if the daemon can run, false if not.
     */
    protected function canRunDaemon()
    {
        $lastRun = time() - $this->getLastRunTime();
        $tooSoon = $lastRun <= $this->runInterval;

        if ($tooSoon) {
            return false;
        }

        $currentState  = $this->getState();
        $lastStateTime = time() - $this->getLastStateChangeTime();

        if ($lastStateTime >= $this->maxRunTime && $currentState > static::STATE_IDLE) {
            return false;
        }

        return $currentState === static::STATE_IDLE;
    }

    /**
     * Runs the daemon.
     *
     * @since [*next-version*]
     */
    public function runDaemon()
    {
        $doingCron     = defined('DOING_CRON') && DOING_CRON;
        $daemonRunSelf = $doingCron && $this->pingSelf;

        if (!$this->canRunDaemon() && !$daemonRunSelf) {
            return;
        }

        $this->setState(static::STATE_QUEUED);

        wp_remote_post($this->daemonUrl, [
            'blocking' => false,
            'timeout'  => 1,
            'body'     => [],
        ]);
    }

    /**
     * Retrieves the option name for the runner's state.
     *
     * @since [*next-version*]
     *
     * @return string
     */
    protected function getStateOptionName()
    {
        return $this->optionPrefix . static::STATE_OPTION_SUFFIX;
    }

    /**
     * Retrieves the option name for the last time the state was changed.
     *
     * @since [*next-version*]
     *
     * @return string
     */
    protected function getLastStateChangeOptionName()
    {
        return $this->optionPrefix . static::LAST_STATE_CHANGE_OPTION_SUFFIX;
    }

    /**
     * Retrieves the option name for the runner's last run time.
     *
     * @since [*next-version*]
     *
     * @return string
     */
    protected function getLastRunOptionName()
    {
        return $this->optionPrefix . static::LAST_RUN_OPTION_SUFFIX;
    }
}
