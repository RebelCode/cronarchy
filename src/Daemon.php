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
     * Whether or not to enable logging.
     *
     * @since [*next-version*]
     *
     * @var bool
     */
    protected $logging;

    /**
     * The path to the log file.
     *
     * @since [*next-version*]
     *
     * @var string
     */
    protected $logFile;

    /**
     * The max number of directories to search for when trying to locate WordPress.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $wpMaxDirSearch;

    /**
     * Whether or not to ignore failed jobs by removing them from the database.
     *
     * @since [*next-version*]
     *
     * @var bool
     */
    protected $ignoreFailedJobs;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId       The ID of the {@link Cronarchy} instance to use.
     * @param string $callerDir        The directory of the caller, i.e. the daemon entry script.
     * @param bool   $log              Whether or not to enable logging.
     * @param string $logFile          The path to the log file.
     * @param int    $wpMaxDirSearch   The max number of directories to search for when trying to locate WordPress.
     * @param bool   $ignoreFailedJobs Whether or not to ignore failed jobs by removing them from the database.
     */
    public function __construct(
        $instanceId,
        $callerDir,
        $log = false,
        $logFile = null,
        $wpMaxDirSearch = null,
        $ignoreFailedJobs = false
    ) {
        $this->instanceId = (string) $instanceId;
        $this->callerDir = $callerDir;
        $this->instanceId = $instanceId;
        $this->logging = $log;
        $this->logFile = $logFile;
        $this->wpMaxDirSearch = $wpMaxDirSearch;
        $this->ignoreFailedJobs = $ignoreFailedJobs;
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

        $this->callerDir = realpath($this->callerDir);
        $this->callerDir = empty($currDir)
            ? __DIR__
            : $currDir;

        $this->logging = filter_var($this->logging, FILTER_VALIDATE_BOOLEAN);

        $this->logFile = empty($this->logFile)
            ? $this->callerDir . '/cronarchy-log.txt'
            : $this->logFile;

        $this->wpMaxDirSearch = filter_var($this->wpMaxDirSearch, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $this->wpMaxDirSearch = empty($this->wpMaxDirSearch)
            ? 10
            : $this->wpMaxDirSearch;

        $this->ignoreFailedJobs = filter_var($this->ignoreFailedJobs, FILTER_VALIDATE_BOOLEAN);

        $this->log('Current working dir = ' . $this->callerDir);
        $this->log('Logging = ' . ($this->logging ? 'on' : 'off'));
        $this->log('Log file = ' . $this->logFile);
        $this->log('Max dir search = ' . $this->wpMaxDirSearch);
        $this->log('Ignore failed jobs = ' . ($this->ignoreFailedJobs ? 'on' : 'off'));
        $this->log(null, -1);
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
        // The different directory structures to cater for, as a list of directory names
        // where the wp-load.php file can be found, relative to the root (ABSPATH).
        static $cDirTypes = [
            '',    // Vanilla installations
            '/wp', // Bedrock installations
        ];

        $dirCount = 0;
        $directory = $this->callerDir;

        do {
            foreach ($cDirTypes as $_suffix) {
                $subDirectory = realpath($directory . $_suffix);
                if (empty($subDirectory)) {
                    continue;
                }

                $this->log(sprintf('Searching in "%s"', $subDirectory));

                if (is_readable($wpLoadFile = $subDirectory . '/wp-load.php')) {
                    $this->log(sprintf('Found WordPress at "%s"', $subDirectory));

                    return $wpLoadFile;
                }
            }

            $directory = realpath($directory . '/..');
            if (++$dirCount > $this->wpMaxDirSearch) {
                break;
            }
        } while (is_dir($directory));

        $this->log('Could not find WordPress manually');

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
        if ($runner->getState() < $runner::STATE_PREPARING) {
            $this->log('Daemon is not in `STATE_PREPARING` state and should not have been executed!');
            exit;
        }

        // Update the execution time limit for this script
        $maxRunTime = $runner->getMaxRunTime();
        $this->log(sprintf('Setting max run time to %d seconds', $maxRunTime));
        set_time_limit($maxRunTime);

        $this->log('Updating Runner state to STATE_RUNNING');
        // Update the runner state to indicate that it is running
        $runner->setState($runner::STATE_RUNNING);

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
            $pendingJobs = $manager->getPendingJobs();

            if (empty($pendingJobs)) {
                $this->log('There are no pending jobs to run!');

                return;
            }

            $this->log('Running jobs ...', 1);

            // Iterate all pending jobs
            foreach ($pendingJobs as $_job) {
                $job = $_job;
                $this->log(sprintf('Retrieved job `%s`', $job->getHook()), 1);

                try {
                    $this->log('Running ... ', 0, false);
                    $job->run();
                    $this->log('done!');
                } catch (Exception $innerException) {
                    $this->log('error:', 1);
                    $this->log($innerException->getMessage(), -1);

                    if (!$this->ignoreFailedJobs) {
                        $this->log('This job will remain in the database to be re-run later', -1);
                        continue;
                    }

                    $this->log(null, -1);
                }

                // Schedule the next recurrence if applicable
                $newJob = $manager->scheduleJobRecurrence($job);
                if ($newJob !== null) {
                    $newJobDate = gmdate('H:i:s, jS M Y', $newJob->getTimestamp());
                    $this->log(sprintf('Scheduled next occurrence to run at %s', $newJobDate));
                }

                // Delete the run job
                $this->log('Removing this job from the database ...');
                $manager->deleteJobs([$job->getId()]);

                $this->log(null, -1);
            }
            $job = null;
        } catch (Exception $outerException) {
            $this->log('Failed to run all jobs:', 1);
            $this->log($outerException->getMessage(), -1);
        }

        $this->log('All pending jobs have been run successfully!');
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

        // If runner is set to ping itself, update the state to preparing, wait for next run, then run again
        $this->log('Updating Runner state to STATE_PREPARING');
        $runner->setState($runner::STATE_PREPARING);

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

        if (!$this->logging) {
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

            file_put_contents($this->logFile, $message, FILE_APPEND);
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
            $this->log('Job ID: ' . $this->currentJob->getId());
            $this->log('Job hook: ' . $this->currentJob->getHook());
            $this->log('Job timestamp: ' . $this->currentJob->getTimestamp());
            $this->log('Job recurrence: ' . $this->currentJob->getRecurrence());
            $this->log(null, -1);
        }

        if ($this->cronarchy instanceof Cronarchy) {
            $runner = $this->cronarchy->getRunner();
            $runner->setState($runner::STATE_IDLE);
            $runner->setLastRunTime();
        }

        $this->log('Exiting ...');
        exit;
    }
}
