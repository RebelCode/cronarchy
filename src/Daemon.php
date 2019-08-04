<?php

namespace RebelCode\Cronarchy;

use Exception;

/**
 * The daemon task for Cronarchy.
 *
 * @since [*next-version*]
 */
class Daemon
{
    /**
     * The ID of the {@link Cronarchy} instance to use.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $instanceId;

    /**
     * The path to the daemon configuration file.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $configPath;

    /**
     * The cronarchy instance.
     *
     * @since [*next-version*]
     *
     * @var Cronarchy|null
     */
    protected $cronarchy = null;

    /**
     * The job that is currently being run.
     *
     * @since [*next-version*]
     *
     * @var Job|null
     */
    protected $currentJob = null;

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
     * @param string $instanceId The ID of the {@link Cronarchy} instance to use.
     * @param string $configPath The path to the daemon configuration file.
     */
    public function __construct(
        $instanceId,
        $configPath
    ) {
        $this->instanceId = (string) $instanceId;
        $this->configPath = $configPath;
    }

    /**
     * Allows daemon instances to be invoked like a function.
     *
     * @since [*next-version*]
     */
    public function __invoke()
    {
        $this->run();
    }

    /**
     * Runs the daemon.
     *
     * @since [*next-version*]
     */
    public function run()
    {
        $this->init();
        $this->loadConfig();
        $this->loadWordPress();
        $this->loadInstance();
        $this->runPendingJobs();
        $this->finish();
    }

    /**
     * Initializes the daemon and the environment.
     *
     * @since [*next-version*]
     */
    public function init()
    {
        // Begin capturing output
        ob_start();

        $this->log(str_repeat('-', 80));
        $this->log('Starting Daemon ...', 1);

        // Ensure the exit function runs when the script terminates unexpectedly
        $this->log('Registering shutdown function ...');
        register_shutdown_function([$this, 'shutdown']);

        // Ensure the exit function runs when the script terminates unexpectedly
        $this->log('Setting PHP to ignore user abortion ...');
        ignore_user_abort(true);

        $this->log(null, -1);
    }

    /**
     * Loads the daemon's configuration.
     *
     * @since [*next-version*]
     */
    public function loadConfig()
    {
        $this->log(sprintf('Loading config from "%s" ...', $this->configPath), 1);

        try {
            $this->config = new Config($this->configPath);
            $this->config->load();
        } catch (Exception $exception) {
            $this->log(sprintf('Error while loading config: "%s"', $exception->getMessage()), -1);
            exit;
        }

        $this->log('Config loaded successfully!', -1);
    }

    /**
     * Loads WordPress from `wp-load.php`.
     *
     * @since [*next-version*]
     */
    public function loadWordPress()
    {
        $this->log('Loading WordPress environment', 1);

        if (!($this->config instanceof Config)) {
            $this->log('Config was not loaded or did not load correctly!', -1);
            exit;
        }

        if (!isset($this->config['wp_path'])) {
            $this->log('Config has missing `wp_path` entry', -1);
            exit;
        }

        $wpPath = rtrim($this->config['wp_path'], '/');
        $this->log(sprintf('Retrieved WordPress path from config: "%s"', $wpPath));

        $this->log('Loading `wp-load.php` ...');
        require_once rtrim($wpPath, '/') . '/wp-load.php';

        $this->log('WordPress has been loaded!', -1);
    }

    /**
     * Loads the Cronarchy instance from the filter and prepares it for running jobs.
     *
     * @since [*next-version*]
     */
    public function loadInstance()
    {
        $this->log('Setting up Cronarchy ...', 1);

        // Get the instance
        $this->log(sprintf('Retrieving instance "%s" from filter ...', $this->instanceId));
        $this->cronarchy = apply_filters(Cronarchy::INSTANCE_FILTER, null, $this->instanceId);

        if (!$this->cronarchy instanceof Cronarchy) {
            $this->log('Invalid instance retrieved from filter');
            exit;
        }

        // Change the daemon's config pointer to point to the instance's config
        $this->log('Swapping config instance ...');
        $this->config = $this->cronarchy->getConfig();

        // Ensure the runner is not in an idle state
        $this->log('Checking Runner state ...');
        $runner = $this->cronarchy->getRunner();
        if ($runner->getState() < $runner::STATE_QUEUED) {
            $this->log('Daemon is not in `STATE_QUEUED` state and should not have been executed!');
            exit;
        }

        $this->log(null, -1);
    }

    /**
     * Runs all pending jobs.
     *
     * @since [*next-version*]
     */
    public function runPendingJobs()
    {
        try {
            $pendingJobs = $this->cronarchy->getManager()->getPendingJobs();

            if (empty($pendingJobs)) {
                $this->log('There are no pending jobs to run!');

                return;
            }

            // Update the runner state to indicate that it is running
            $runner = $this->cronarchy->getRunner();
            $runner->setState($runner::STATE_RUNNING);
            $this->log('Running jobs ...', 1);

            foreach ($pendingJobs as $job) {
                // Extend the time limit for this job
                set_time_limit($this->config['max_job_run_time']);
                // Run the job
                $this->runJob($this->currentJob = $job);
                $this->currentJob = null;
            }

            $this->log(null, -1);
        } catch (Exception $exception) {
            $this->log('Exception: ' . $exception->getMessage());
        }

        $this->log('All pending jobs have been run successfully!');
    }

    /**
     * Runs a single job.
     *
     * @since [*next-version*]
     *
     * @param Job $job The job to run.
     *
     * @throws Exception If failed to schedule the job recurrence or delete the job.
     */
    protected function runJob(Job $job)
    {
        $manager = $this->cronarchy->getManager();

        try {
            $this->log(sprintf('Running job with hook `%s`... ', $job->getHook()), 0, false);
            $job->run();
            $this->log('done!');
        } catch (Exception $exception) {
            $this->log('error!', 1);

            if (!$this->config['delete_failed_jobs']) {
                $this->log('This job will remain in the database to be re-run later');

                return;
            }

            $this->log($exception->getMessage(), -1);
        }

        // Schedule the next recurrence if applicable
        $newJob = $manager->scheduleJobRecurrence($job);
        if ($newJob !== null) {
            $newJobDate = gmdate('H:i:s, jS M Y', $newJob->getTimestamp());
            $this->log(sprintf('Scheduled next occurrence to run at %s', $newJobDate));
        }

        // Delete the run job
        $this->log('Removing job from the database ...');
        $manager->deleteJobs([$job->getId()]);
    }

    /**
     * Performs clean up and terminates the daemon or pings itself through the runner, depending on configuration.
     *
     * @since [*next-version*]
     */
    public function finish()
    {
        $this->log(null, -1);
        $this->log('Cleaning up ...', 1);

        // Capture and flush any generated output
        $this->log('Flushing output ...');
        ob_get_clean();

        $runner = $this->cronarchy->getRunner();

        // If the runner is not set to ping itself, clean up and exit
        if (!$this->config['self_pinging']) {
            $this->log('Daemon finished successfully', -1);
            exit;
        }

        // If runner is set to ping itself, update the state to idle, wait for next run, then run again
        $this->log('Updating Runner state to self::STATE_IDLE');
        $runner->setState($runner::STATE_IDLE);

        $this->log('Sleeping ...');
        sleep($this->config['run_interval']);

        $this->log('Pinging self through Runner', -1);
        $runner->runDaemon();
    }

    /**
     * Logs a message to file.
     *
     * @since [*next-version*]
     *
     * @param string|null $text      The text to log.
     * @param int         $modIndent How much to change the indentation. Negative numbers are allowed. This applies AFTER the
     *                               log message has been written to file.
     * @param bool        $endLine   True to end the line, false to not.
     */
    public function log($text, $modIndent = 0, $endLine = true)
    {
        static $indent    = 0;
        static $continues = false;

        if (!$this->config['logging_enabled']) {
            return;
        }

        if ($text !== null) {
            $prefix = (!$continues)
                ? sprintf('[%s] ', date('d M y - H:i:s'))
                : '';
            $indentStr = (!$continues)
                ? str_repeat(' ', $indent * 4)
                : '';
            $eolChar = ($endLine)
                ? PHP_EOL
                : '';

            $message = $prefix . $indentStr . $text . $eolChar;

            file_put_contents($this->config['log_file_path'], $message, FILE_APPEND);
        }

        $continues = !$endLine;
        $indent    = max(0, $indent + $modIndent);
    }

    /**
     * Performs clean up and finalization.
     *
     * Should be called when the script terminates, both successfully or otherwise.
     *
     * @since [*next-version*]
     */
    public function shutdown()
    {
        if ($this->currentJob instanceof Job) {
            $this->log('', -100);
            $this->log('Daemon script ended unexpectedly while running a job', 1);
            $this->log('Job ID: ' . $this->currentJob->getId());
            $this->log('Job hook: ' . $this->currentJob->getHook());
            $this->log('Job timestamp: ' . $this->currentJob->getTimestamp());
            $this->log('Job recurrence: ' . $this->currentJob->getRecurrence());
            $this->log(null, -1);
        }

        if ($this->cronarchy instanceof Cronarchy) {
            $runner = $this->cronarchy->getRunner();
            $runner->setState($runner::STATE_STOPPED);
            $runner->setLastRunTime();
        }

        $this->log('Exiting ...');
        exit;
    }
}
