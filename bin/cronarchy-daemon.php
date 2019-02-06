<?php

namespace RebelCode\Cronarchy\Daemon;

use Exception;
use RebelCode\Cronarchy\Cronarchy;
use RebelCode\Cronarchy\Job;

//=============================================================================
// START
//=============================================================================

// Begin capturing output
ob_start();
// Ignore user abortion
ignore_user_abort(true);

logWrite(str_repeat('-', 80));
logWrite('Starting Daemon ...', 1);

// Ensure the exit function runs when the script terminates unexpectedly
logWrite('Registering shutdown function ...');
register_shutdown_function('RebelCode\Cronarchy\Daemon\shutdown');

logWrite('Declaring global instance ...');
global $cronarchy;

logWrite(null, -1);

//=============================================================================
// PREPARE CONFIG
//=============================================================================

logWrite('Loading config ...', 1);

// The name of the WordPress filter to use to retrieve the Cronarchy instance
if (!defined('CRONARCHY_INSTANCE_FILTER')) {
    define('CRONARCHY_INSTANCE_FILTER', ''); // For IDE auto completion
    logWrite('The `CRONARCHY_INSTANCE_FILTER` constant is not set!');
    exit;
}

// The current working directory to use
if (!defined('CRONARCHY_DAEMON_CWD')) {
    define('CRONARCHY_DAEMON_CWD', __DIR__);
}

// Whether or not to enable logging
if (!defined('CRONARCHY_DAEMON_LOG')) {
    define('CRONARCHY_DAEMON_LOG', false);
}

// The path to the log file
if (!defined('CRONARCHY_DAEMON_LOG_FILE')) {
    define('CRONARCHY_DAEMON_LOG_FILE', __DIR__ . '/cronarchy_log.txt');
}

// The max number of parent directories to search for when locating the wp-load.php file
if (!defined('CRONARCHY_MAX_DIR_SEARCH')) {
    define('CRONARCHY_MAX_DIR_SEARCH', 20);
}

// Whether or not to leave jobs that fail in the database to be run again later
if (!defined('CRONARCHY_RETRY_FAILED_JOBS')) {
    define('CRONARCHY_RETRY_FAILED_JOBS', true);
}

logWrite('Current working dir = ' . CRONARCHY_DAEMON_CWD);
logWrite('Logging = ' . (CRONARCHY_DAEMON_LOG ? 'on' : 'off'));
logWrite('Log file = ' . realpath(CRONARCHY_DAEMON_LOG_FILE));
logWrite('Max dir search = ' . CRONARCHY_MAX_DIR_SEARCH);
logWrite('Retry failed jobs = ' . (CRONARCHY_RETRY_FAILED_JOBS ? 'on' : 'off'));
logWrite(null, -1);

//=============================================================================
// LOAD WORDPRESS
//=============================================================================

logWrite('Loading WordPress environment', 1);
logWrite('Searching for `wp-load.php` file', 1);

if (($wpLoadFilePath = findWordPress()) === null) {
    exit;
}

logWrite(null, -1);

// Define WordPress Cron constant for compatibility with themes and plugins
logWrite('Defining `DOING_CRON` constant ...');
define('DOING_CRON', true);

// Load WordPress
logWrite('Loading `wp-load.php` ...');
require_once $wpLoadFilePath;

logWrite('WordPress has been loaded!', -1);

//=============================================================================
// CRONARCHY INSTANCE
//=============================================================================

logWrite('Setting up Cronarchy ...', 1);

// Get the instance
logWrite('Retrieving instance from filter ...');
$cronarchy = apply_filters(CRONARCHY_INSTANCE_FILTER, null);
if (!$cronarchy instanceof Cronarchy) {
    logWrite('Invalid instance retrieved from filter');
    exit;
}

logWrite('Reading instance manager ...');
$manager = $cronarchy->getManager();

logWrite('Reading instance runner ...');
$runner = $cronarchy->getRunner();

logWrite('Checking Runner state ...');
// Ensure the runner is not in an idle state
if ($runner->getState() < $runner::STATE_PREPARING) {
    logWrite('Daemon is not in `STATE_PREPARING` state and should not have been executed!');
    exit;
}

// Update the execution time limit for this script
$maxRunTime = $runner->getMaxRunTime();
logWrite(sprintf('Setting max run time to %d seconds', $maxRunTime));
set_time_limit($maxRunTime);

logWrite('Updating Runner state to STATE_RUNNING');
// Update the runner state to indicate that it is running
$runner->setState($runner::STATE_RUNNING);

logWrite(null, -1);

//=============================================================================
// RUN JOBS
//=============================================================================

global $job;
$job = null;

do {
    try {
        $pendingJobs = $manager->getPendingJobs();

        if (empty($pendingJobs)) {
            logWrite('There are no pending jobs to run!');
            break;
        }

        logWrite('Running jobs ...', 1);

        // Iterate all pending jobs
        foreach ($pendingJobs as $_job) {
            $job = $_job;
            logWrite(sprintf('Retrieved job `%s`', $job->getHook()), 1);

            try {
                logWrite('Running ... ', 0, false);
                $job->run();
                logWrite('done!');
            } catch (Exception $innerException) {
                logWrite('error:', 1);
                logWrite($innerException->getMessage(), -1);

                if (CRONARCHY_RETRY_FAILED_JOBS) {
                    logWrite('This job will remain in the database to be re-run later', -1);
                    continue;
                }

                logWrite(null, -1);
            }

            // Schedule the next recurrence if applicable
            $newJob = $manager->scheduleJobRecurrence($job);
            if ($newJob !== null) {
                $newJobDate = gmdate('H:i:s, jS M Y', $newJob->getTimestamp());
                logWrite(sprintf('Scheduled next occurrence to run at %s', $newJobDate));
            }

            // Delete the run job
            logWrite('Removing this job from the database ...');
            $manager->deleteJobs([$job->getId()]);

            logWrite(null, -1);
        }
        $job = null;
    } catch (Exception $outerException) {
        logWrite('Failed to run all jobs:', 1);
        logWrite($outerException->getMessage(), -1);
    }

    logWrite('There are no more pending jobs to run');
} while (false);

//=============================================================================
// END
//=============================================================================

logWrite(null, -1);
logWrite('Cleaning up ...', 1);

// Capture and flush any generated output
logWrite('Flushing output ...');
ob_get_clean();

// If the runner is not set to ping itself, clean up and exit
if (!$runner->isSelfPinging()) {
    logWrite('Daemon finished successfully', -1);
    exit;
}

// If runner is set to ping itself, update the state to preparing, wait for next run, then run again
logWrite('Updating Runner state to STATE_PREPARING');
$runner->setState($runner::STATE_PREPARING);

logWrite('Sleeping ...');
sleep($runner->getRunInterval());

logWrite('Pinging self through Runner', -1);
$runner->runDaemon(true);

//=============================================================================
// FUNCTIONS
//=============================================================================

/**
 * Finds the path to the WordPress `wp-load.php` file.
 *
 * @since [*next-version*]
 *
 * @return string|null The path to the file if found, or null if not found.
 */
function findWordPress()
{
    // The different directory structures to cater for, as a list of directory names
    // where the wp-load.php file can be found, relative to the root (ABSPATH).
    static $cDirTypes = [
        '',    // Vanilla installations
        '/wp', // Bedrock installations
    ];

    $dirCount = 0;
    $directory = CRONARCHY_DAEMON_CWD;

    do {
        foreach ($cDirTypes as $_suffix) {
            $subDirectory = realpath($directory . $_suffix);
            if (empty($subDirectory)) {
                continue;
            }

            logWrite(sprintf('Searching in "%s"', $subDirectory));

            if (is_readable($wpLoadFile = $subDirectory . '/wp-load.php')) {
                logWrite(sprintf('Found WordPress at "%s"', $subDirectory));

                return $wpLoadFile;
            }
        }

        $directory = realpath($directory . '/..');
        if (++$dirCount > CRONARCHY_MAX_DIR_SEARCH) {
            break;
        }
    } while (is_dir($directory));

    logWrite('Could not find WordPress manually');

    return null;
}

/**
 * Logs a message to file.
 *
 * @since [*next-version*]
 *
 * @param string $text      The text to log.
 * @param int    $modIndent How much to change the indentation. Negative numbers are allowed. This applies AFTER the
 *                          log message has been written to file.
 */
function logWrite($text, $modIndent = 0, $endLine = true)
{
    static $indent = 0;
    static $continues = false;

    if (!CRONARCHY_DAEMON_LOG) {
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

        file_put_contents(CRONARCHY_DAEMON_LOG_FILE, $message, FILE_APPEND);
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
function shutdown()
{
    global $cronarchy;
    global $job;

    if ($job instanceof Job) {
        logWrite('', -100);
        logWrite('Daemon script ended unexpectedly while running a job', 1);
        logWrite('Job ID: ' . $job->getId());
        logWrite('Job hook: ' . $job->getHook());
        logWrite('Job timestamp: ' . $job->getTimestamp());
        logWrite('Job recurrence: ' . $job->getRecurrence());
        logWrite(null, -1);
    }

    if ($cronarchy instanceof Cronarchy) {
        $runner = $cronarchy->getRunner();
        $runner->setState($runner::STATE_IDLE);
        $runner->setLastRunTime();
    }

    logWrite('Exiting ...');
    exit;
}
