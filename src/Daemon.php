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
     * The config key for enabling/disabling logging.
     *
     * @since [*next-version*]
     */
    const ENABLE_LOGGING = 'enable_logging';

    /**
     * The config key for specifying the path of the log file to write logs to.
     *
     * @since [*next-version*]
     */
    const LOG_FILE_PATH = 'log_file_path';

    /**
     * The config key for maximum number of directories to search in when locating WordPress.
     *
     * @since [*next-version*]
     */
    const MAX_DIR_SEARCH = 'max_dir_search';

    /**
     * The config key for enabling or disabling the deletion of failed jobs.
     *
     * @since [*next-version*]
     */
    const DELETE_FAILED_JOBS = 'delete_failed_jobs';

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
     * The ID of the {@link Cronarchy} instance to use.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $instanceId;

    /**
     * The directory of the caller, i.e. the daemon entry script.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $callerDir;

    /**
     * The daemon's configuration.
     *
     * @since [*next-version*]
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId The ID of the {@link Cronarchy} instance to use.
     * @param string $callerDir  The directory of the caller, i.e. the daemon entry script.
     * @param array  $config     The daemon's configuration.
     */
    public function __construct(
        $instanceId,
        $callerDir,
        $config = []
    ) {
        $this->instanceId = (string) $instanceId;
        $this->callerDir = $callerDir;
        $this->instanceId = $instanceId;
        $this->config = $config;
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
        $this->log('Loading config ...', 1);

        if (empty($this->instanceId)) {
            $this->log('The instance ID is invalid or not set!');
            exit;
        }

        $this->config = array_merge($this->getDefaultConfig(), $this->config);

        $this->log('Config:', 1);
        $this->log(print_r($this->config, true), -1);
    }

    /**
     * Loads WordPress from `wp-load.php`.
     *
     * @since [*next-version*]
     */
    public function loadWordPress()
    {
        $this->log('Loading WordPress environment', 1);
        $this->log('Searching for `wp-load.php` file', 1);

        if (($wpLoadFilePath = $this->findWordPress()) === null) {
            exit;
        }

        $this->log(null, -1);

        // Define WordPress Cron constant for compatibility with themes and plugins
        $this->log('Defining `DOING_CRON` constant ...');
        define('DOING_CRON', true);

        // Load WordPress
        $this->log('Loading `wp-load.php` ...');
        require_once $wpLoadFilePath;

        $this->log('WordPress has been loaded!', -1);
    }

    /**
     * Finds the path to the WordPress `wp-load.php` file.
     *
     * @since [*next-version*]
     *
     * @return string|null The path to the file if found, or null if not found.
     */
    public function findWordPress()
    {
        $dirCount = 0;
        $maxCount = $this->config[static::MAX_DIR_SEARCH];
        $directory = $this->callerDir;

        do {
            $this->log(sprintf('Searching in "%s"', $directory));
            $wpLoadFile = $this->findWordPressFromDirectory($directory);

            if ($wpLoadFile !== null) {
                $this->log(sprintf('Found WordPress: "%s"', $wpLoadFile));

                return $wpLoadFile;
            }

            $directory = realpath($directory.'/..');
            ++$dirCount;
        } while (is_dir($directory) && $dirCount < $maxCount);

        $this->log('Could not find WordPress manually');

        return null;
    }

    /**
     * Searches for WordPress from a specific directory, testing various different known installation types.
     *
     * @since [*next-version*]
     *
     * @param string $directory The path of the directory to search in.
     *
     * @return string|null The path to the WordPress `wp-load.php` file, or null if not found.
     */
    protected function findWordPressFromDirectory($directory)
    {
        // The list of directory structures to search in, relative to the root (ABSPATH).
        $dirTypes = [
            '',    // Vanilla installations
            '/wp', // Bedrock installations
        ];

        foreach ($dirTypes as $_suffix) {
            $subDirectory = realpath($directory.$_suffix);
            $wpLoadFile = $subDirectory.'/wp-load.php';

            if (!empty($subDirectory) && is_readable($wpLoadFile)) {
                $this->log(sprintf('Found WordPress at "%s"', $subDirectory));

                return $wpLoadFile;
            }
        }

        return null;
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

        $runner = $this->cronarchy->getRunner();

        $this->log('Checking Runner state ...');
        // Ensure the runner is not in an idle state
        if ($runner->getState() < $runner::STATE_QUEUED) {
            $this->log('Daemon is not in `STATE_QUEUED` state and should not have been executed!');
            exit;
        }

        // Update the execution time limit for this script
        $maxRunTime = $runner->getMaxRunTime();
        $this->log(sprintf('Setting max run time to %d seconds', $maxRunTime));
        set_time_limit($maxRunTime);

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
            $manager = $this->cronarchy->getManager();
            $runner = $this->cronarchy->getRunner();
            $pendingJobs = $manager->getPendingJobs();

            if (empty($pendingJobs)) {
                $this->log('There are no pending jobs to run!');

                return;
            }

            // Update the runner state to indicate that it is preparing to run
            $runner->setState($runner::STATE_RUNNING);
            $this->log('Running jobs ...', 1);

            foreach ($pendingJobs as $job) {
                $this->currentJob = $job;
                $this->runJob($job, $manager);
                $this->currentJob = null;
            }

            $this->log(null, -1);
        } catch (Exception $exception) {
            $this->log('Exception: '.$exception->getMessage());
        }

        $this->log('All pending jobs have been run successfully!');
    }

    /**
     * Runs a single job.
     *
     * @since [*next-version*]
     *
     * @param Job        $job     The job to run.
     * @param JobManager $manager The manager to use to schedule recurrences.
     *
     * @throws Exception If failed to schedule the job recurrence or delete the job.
     */
    protected function runJob(Job $job, JobManager $manager)
    {
        try {
            $this->log(sprintf('Running job with hook `%s`... ', $job->getHook()), 0, false);
            $job->run();
            $this->log('done!');
        } catch (Exception $exception) {
            $this->log('error!', 1);

            if (!$this->config[static::DELETE_FAILED_JOBS]) {
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
        if (!$runner->isSelfPinging()) {
            $this->log('Daemon finished successfully', -1);
            exit;
        }

        // If runner is set to ping itself, update the state to idle, wait for next run, then run again
        $this->log('Updating Runner state to self::STATE_IDLE');
        $runner->setState($runner::STATE_IDLE);

        $this->log('Sleeping ...');
        sleep($runner->getRunInterval());

        $this->log('Pinging self through Runner', -1);
        $runner->runDaemon();
    }

    /**
     * Logs a message to file.
     *
     * @since [*next-version*]
     *
     * @param string $text      The text to log.
     * @param int    $modIndent How much to change the indentation. Negative numbers are allowed. This applies AFTER the
     *                          log message has been written to file.
     * @param bool   $endLine   True to end the line, false to not.
     */
    public function log($text, $modIndent = 0, $endLine = true)
    {
        static $indent = 0;
        static $continues = false;

        if (!$this->config[static::ENABLE_LOGGING]) {
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

            $message = $prefix.$indentStr.$text.$eolChar;

            file_put_contents($this->config[static::LOG_FILE_PATH], $message, FILE_APPEND);
        }

        $continues = !$endLine;
        $indent = max(0, $indent + $modIndent);
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
            $this->log('Job ID: '.$this->currentJob->getId());
            $this->log('Job hook: '.$this->currentJob->getHook());
            $this->log('Job timestamp: '.$this->currentJob->getTimestamp());
            $this->log('Job recurrence: '.$this->currentJob->getRecurrence());
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

    /**
     * Retrieves the default config values.
     *
     * @since [*next-version*]
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return [
            static::ENABLE_LOGGING => false,
            static::LOG_FILE_PATH => $this->callerDir.'/cronarchy-log.txt',
            static::MAX_DIR_SEARCH => 10,
            static::DELETE_FAILED_JOBS => false,
        ];
    }
}
