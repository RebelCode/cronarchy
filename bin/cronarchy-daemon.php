<?php

use RebelCode\Cronarchy\Cronarchy;
use RebelCode\Cronarchy\DaemonRunner;
use RebelCode\Cronarchy\Job;

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

    exit;
}

// Begin capturing output
ob_start();
// Ingore user abortion
ignore_user_abort(true);
// Ensure the exit function runs when the script terminates unexpectedly
register_shutdown_function('cronarchyExit');
// If possible, also ensure the exit function runs when the script is sent a termination signal
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'cronarchyExit');
    pcntl_signal(SIGHUP, 'cronarchyExit');
    pcntl_signal(SIGUSR1, 'cronarchyExit');
}

// Start the session
session_start();
// Load data from the session
$cronarchyWpFile = $_SESSION[DaemonRunner::SESSION_WP_DIR_KEY] . DIRECTORY_SEPARATOR . 'wp-load.php';
$cronarchyFilter = $_SESSION[DaemonRunner::SESSION_FILTER_KEY];
// Ensure the data is valid. Otherwise stop here
if (!is_readable($cronarchyWpFile) || empty($cronarchyFilter)) {
    cronarchyExit();
}

// Define WordPress Cron constant for compatibility with themes and plugins
define('DOING_CRON', true);
// Load WordPress
require_once $cronarchyWpFile;

// Get the instance
$instance = apply_filters($cronarchyFilter, null);
if (!$instance instanceof Cronarchy) {
    cronarchyExit($instance);
}
// Get the instance's components
$manager = $instance->getManager();
$runner = $instance->getRunner();
// Update the execution time limit for this script
set_time_limit($runner->getMaxRunTime());

// Ensure the runner is not in an idle state
if ($runner->getState() < $runner::STATE_PREPARING) {
    cronarchyExit($instance);
}
// Update the runner state to indicate that it is running
$runner->setState($runner::STATE_RUNNING);

try {
    // Run all pending jobs
    foreach ($manager->getPendingJobs() as $job) {
        $job->run();

        // If the job has a set recurrence time, schedule another job at that time
        $recurrence = $job->getRecurrence();
        if ($recurrence !== null && $recurrence > 0) {
            $newJob = new Job(
                null,
                $job->getTimestamp() + $recurrence,
                $job->getHook(),
                $job->getArgs(),
                $recurrence
            );
            $manager->scheduleJob($newJob);
        }

        // Remove this job from storage
        $manager->cancelJob($job->getId());
    }
} catch (Exception $exception) {
}

// Capture and flush any generated output
ob_get_clean();

// If the runner is not set to ping itself, clean up and exit
if (!$runner->isSelfPinging()) {
    cronarchyExit($instance);
}

// If runner is set to ping itself, update the state to preparing, wait for next run, then run again
$runner->setState($runner::STATE_PREPARING);
sleep($runner->getRunInterval());
$runner->runDaemon(true);
