<?php

use RebelCode\Cronarchy\Cronarchy;
use RebelCode\Cronarchy\DaemonRunner;

/**
 * Performs clean up and finalization.
 *
 * Should be called when the script terminates, both successfully or otherwise.
 *
 * @since [*next-version*]
 *
 * @param Cronarchy|null $instance Optional cronarchy instance, if one was obtained before this function was called.
 */
function cronarchyExit(Cronarchy $instance = null)
{
    if ($instance instanceof Cronarchy) {
        $runner = $instance->getRunner();
        $runner->setState($runner::STATE_IDLE);
        $runner->setLastRunTime();
    }

    cronarchyLog('Exiting ...');
    exit;
}

/**
 * Logs a message to file.
 *
 * @since [*next-version*]
 *
 * @param string $text The text to log.
 * @param int $modIndent How much to change the indentation. Negative numbers are allowed. This applies AFTER the
 *                       log message has been written to file.
 */
function cronarchyLog($text, $modIndent = 0)
{
    static $indent = 0;

    if ($text !== null) {
        $date = date('D, d M Y - H:i:s');
        $indentStr = str_repeat(' ', $indent * 2);
        $message = sprintf('[%s] %s%s' . PHP_EOL, $date, $indentStr, $text);
        file_put_contents(__DIR__ . '/cronarchy_log.txt', $message, FILE_APPEND);
    }

    $indent += $modIndent;
}

cronarchyLog(str_repeat('-', 80));
cronarchyLog('Daemon has started!');

// Begin capturing output
ob_start();
// Ingore user abortion
ignore_user_abort(true);

//=============================================================================
// READ SESSION
//=============================================================================

cronarchyLog('Starting session ...', 1);
session_start();

// Read WP directory path from session
cronarchyLog('Reading path to WordPress directory ...', 1);
$cronarchyWpFile = @$_SESSION[DaemonRunner::SESSION_WP_DIR_KEY] . 'wp-load.php';
if (!is_readable($cronarchyWpFile)) {
    cronarchyLog('Path to WordPress wp-load.php file in session is invalid or file is not readable', -1);
    exit;
}
cronarchyLog($cronarchyWpFile, -1);

// Read instance filter name from session
cronarchyLog('Reading Cronarchy instance filter from session ...', 1);
$cronarchyFilter = @$_SESSION[DaemonRunner::SESSION_FILTER_KEY];
if (empty($cronarchyFilter)) {
    cronarchyLog('Missing WordPress Cronarchy instance filter in session', -1);
    exit;
}
cronarchyLog($cronarchyWpFile, -2);

//=============================================================================
// LOAD WORDPRESS
//=============================================================================

cronarchyLog('Setting up WordPress environment ...', 1);

// Define WordPress Cron constant for compatibility with themes and plugins
cronarchyLog('Defining `DOING_CRON` constant ...');
define('DOING_CRON', true);
// Load WordPress
cronarchyLog('Loading `wp-load.php` ...');
require_once $cronarchyWpFile;
cronarchyLog(null, -1);
cronarchyLog('WordPress has been loaded!');

//=============================================================================
// CRONARCHY INSTANCE
//=============================================================================

cronarchyLog('Setting up Cronarchy ...', 1);

// Get the instance
cronarchyLog('Retrieving instance ...', 1);
$instance = apply_filters($cronarchyFilter, null);
if (!$instance instanceof Cronarchy) {
    cronarchyLog('Invalid instance retrieved from filter', -1);
    exit;
}
cronarchyLog(get_class($instance), -1);

cronarchyLog('Registering shutdown function with instance param ...');
// Ensure the exit function runs when the script terminates unexpectedly
register_shutdown_function('cronarchyExit', $instance);

cronarchyLog('Reading instance manager ...', 1);
$manager = $instance->getManager();
cronarchyLog(get_class($manager), -1);

cronarchyLog('Reading instance runner ...', 1);
$runner = $instance->getRunner();
cronarchyLog(get_class($runner), -1);

// Update the execution time limit for this script
cronarchyLog('Setting max run time according to Runner', 1);
set_time_limit($maxRunTime = $runner->getMaxRunTime());
cronarchyLog(sprintf('Max run time: %d seconds', $maxRunTime), -1);

cronarchyLog('Checking Runner state ...', 1);
// Ensure the runner is not in an idle state
if ($runner->getState() < $runner::STATE_PREPARING) {
    cronarchyLog('Daemon is not in STATE_PREPARING state and should not have been invoked', -1);
    exit;
}

cronarchyLog('Updating Runner state to STATE_RUNNING', -1);
// Update the runner state to indicate that it is running
$runner->setState($runner::STATE_RUNNING);

cronarchyLog(null, -1);

//=============================================================================
// RUN JOBS
//=============================================================================

cronarchyLog('Running ...', 1);

do {
    try {
        cronarchyLog('Retrieving pending jobs ...');
        $pendingJobs = $manager->getPendingJobs();

        if (empty($pendingJobs)) {
            cronarchyLog('There are no jobs pending for execution.');
            break;
        }

        // Iterate all pending jobs
        foreach ($manager->getPendingJobs() as $job) {
            try {
                // Run the job
                $job->run();
            } catch (Exception $innerException) {
                cronarchyLog('A job has erred:', 1);
                cronarchyLog($innerException->getMessage(), -1);
                // Skip the recurrence scheduling and deletion
                continue;
            }
            // Schedule the next recurrence if applicable
            $manager->scheduleJobRecurrence($job);
            // Delete the run job
            $manager->deleteJobs([$job->getId()]);
        }
    } catch (Exception $outerException) {
        cronarchyLog('Daemon failed to run all jobs:', 1);
        cronarchyLog($outerException->getMessage(), -1);
    }
} while (false);

cronarchyLog(null, -1);
cronarchyLog('Flushing output ...');
// Capture and flush any generated output
ob_get_clean();

// If the runner is not set to ping itself, clean up and exit
if (!$runner->isSelfPinging()) {
    cronarchyLog('Daemon finished successfully');
    exit;
}

// If runner is set to ping itself, update the state to preparing, wait for next run, then run again
cronarchyLog('Updating Runner state to STATE_PREPARING');
$runner->setState($runner::STATE_PREPARING);

cronarchyLog('Sleeping ...');
sleep($runner->getRunInterval());

cronarchyLog('Pinging self through Runner');
$runner->runDaemon(true);
