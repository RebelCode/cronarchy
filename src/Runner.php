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
    const OPTION_STATE = 'cronarchy_state';

    /**
     * The suffix of the name of the option where the timestamp for the last state change is stored.
     *
     * @since [*next-version*]
     */
    const OPTION_LAST_STATE_CHANGE = 'cronarchy_last_state_change';

    /**
     * The suffix of the name of the option where the daemon's last run timestamp is stored.
     *
     * @since [*next-version*]
     */
    const OPTION_LAST_RUN = 'cronarchy_last_run';

    /**
     * The ID of the Cronarchy instance.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $instanceId;

    /**
     * The absolute URL to the daemon script.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $daemonUrl;

    /**
     * The config instance.
     *
     * @since [*next-version*]
     *
     * @var Config
     */
    protected $config;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId The prefix to use for options in the wp_options table.
     * @param string $daemonUrl  The absolute URL to the daemon script.
     * @param Config $config     The config instance.
     */
    public function __construct(
        $instanceId,
        $daemonUrl,
        Config $config
    ) {
        $this->instanceId = $instanceId;
        $this->daemonUrl  = $daemonUrl;
        $this->config     = $config;
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
        return intval($this->getOption(static::OPTION_STATE));
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
        $this->saveOption(static::OPTION_STATE, intval($state));
        $this->saveOption(static::OPTION_LAST_STATE_CHANGE, time());
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
        return intval($this->getOption(static::OPTION_LAST_STATE_CHANGE, time()));
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
        return intval($this->getOption(static::OPTION_LAST_RUN, 0));
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
        $this->saveOption(static::OPTION_LAST_RUN, $value);
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
        $interval = $this->config['run_interval'];
        $tooSoon = $lastRun <= $interval;

        if ($tooSoon) {
            return false;
        }

        $currentState  = $this->getState();
        $lastStateTime = time() - $this->getLastStateChangeTime();

        // If the daemon has been stuck in "queued" state for longer than the run interval, queue it again
        if ($currentState === static::STATE_QUEUED && $lastStateTime > $interval) {
            return true;
        }

        // If the daemon is preparing or running, don't queue it unless it's gone over the max run time
        if ($currentState > static::STATE_QUEUED && $lastStateTime < $this->config['max_total_run_time']) {
            return false;
        }

        return $currentState === static::STATE_STOPPED;
    }

    /**
     * Runs the daemon.
     *
     * @since [*next-version*]
     */
    public function runDaemon()
    {
        $doingCron     = defined('DOING_CRON') && DOING_CRON;
        $daemonRunSelf = $doingCron && $this->config['self_pinging'];

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
        return $this->instanceId . static::OPTION_STATE;
    }

    /**
     * Retrieves the value of a database option.
     *
     * @since [*next-version*]
     *
     * @param string     $name    The name of the option whose value is to be retrieved.
     * @param mixed|null $default The default value to return if the option does not exist.
     *
     * @return mixed The option value.
     */
    protected function getOption($name, $default = null)
    {
        return get_option($this->getOptionName($name), $default);
    }

    /**
     * Saves an option's value to the database.
     *
     * @since [*next-version*]
     *
     * @param string $name  The name of the option whose value is to be saved.
     * @param mixed  $value The value to save for the option.
     *
     * @return bool True on success, false on failure.
     */
    protected function saveOption($name, $value)
    {
        return update_option($this->getOptionName($name), $value);
    }

    /**
     * Retrieves the full option name, prefixes with the instance ID.
     *
     * @since [*next-version*]
     *
     * @param string $name The short option name.
     *
     * @return string The full prefixed option name.
     */
    protected function getOptionName($name)
    {
        return sprintf('%s_%s', $this->instanceId, $name);
    }
}
