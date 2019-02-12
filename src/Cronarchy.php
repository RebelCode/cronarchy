<?php

namespace RebelCode\Cronarchy;

use DateTime;
use Exception;

/**
 * The main class for cronarchy, comprised of a job manager and a daemon runner.
 *
 * @since [*next-version*]
 */
class Cronarchy
{
    /**
     * The name of the filter that is used to retrieve instances.
     *
     * @since [*next-version*]
     */
    const INSTANCE_FILTER = 'get_cronarchy_instance';

    /**
     * The instance ID.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $instanceId;

    /**
     * The job manager instance.
     *
     * @since [*next-version*]
     *
     * @var JobManager
     */
    protected $manager;

    /**
     * The daemon runner instance.
     *
     * @since [*next-version*]
     *
     * @var DaemonRunner
     */
    protected $runner;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string       $instanceId The instance ID. Must be unique.
     * @param JobManager   $manager    The job manager instance.
     * @param DaemonRunner $runner     The daemon runner instance.
     */
    public function __construct($instanceId, JobManager $manager, DaemonRunner $runner)
    {
        $this->instanceId = $instanceId;
        $this->manager    = $manager;
        $this->runner     = $runner;
    }

    /**
     * Retrieves the manager instance ID.
     *
     * @since [*next-version*]
     */
    public function getInstanceId()
    {
        return $this->instanceId;
    }

    /**
     * Retrieves the job manager instance.
     *
     * @since [*next-version*]
     *
     * @return JobManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Retrieves the daemon runner instance.
     *
     * @since [*next-version*]
     *
     * @return DaemonRunner
     */
    public function getRunner()
    {
        return $this->runner;
    }

    /**
     * Initializes the instance.
     *
     * @since [*next-version*]
     */
    public function init()
    {
        add_filter(static::INSTANCE_FILTER, function ($pInstance, $id) {
            if ($id === $this->instanceId) {
                return $this;
            }

            return $pInstance;
        }, 10, 2);

        add_action('init', function () {
            if (!(defined('DOING_CRON') && DOING_CRON)) {
                $this->getRunner()->runDaemon();
            }
        });
    }

    /**
     * Creates a new cronarchy instance.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId  An ID for the instance. Must be unique site-wide.
     * @param string $daemonUrl   The absolute URL to the daemon file.
     * @param int    $runInterval The max interval, in seconds, between cron runs. Default is 10 seconds.
     * @param int    $maxRunTime  The max time, in seconds, that a single job can run for. Default is 10 minutes.
     *
     * @throws Exception If an error occurs.
     *
     * @return Cronarchy The created instance.
     */
    public static function setup($instanceId, $daemonUrl, $runInterval = 10, $maxRunTime = 600)
    {
        global $wpdb;

        $jobsTable = new Table(
            $wpdb,
            sprintf('%s_jobs', $instanceId),
            'CREATE TABLE IF NOT EXISTS `{{table}}` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `hook` varchar(255) NOT NULL,
                `args` longtext NOT NULL,
                `timestamp` datetime NOT NULL,
                `recurrence` bigint DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;'
        );
        $jobsTable->init();
        $jobsTable->query("SET time_zone='%s';", [static::getJobsTableTimezone()]);

        $manager  = new JobManager($instanceId, $jobsTable);
        $runner   = new DaemonRunner($daemonUrl, "{$instanceId}_", $runInterval, $maxRunTime);
        $instance = new self($instanceId, $manager, $runner);

        return $instance;
    }

    /**
     * Retrieves the timezone to use for the jobs table.
     *
     * @since [*next-version*]
     *
     * @throws Exception In case of an error.
     *
     * @return string The MySQL timezone offset string.
     */
    protected static function getJobsTableTimezone()
    {
        $now = new DateTime();

        $offset  = (int) $now->getOffset();
        $seconds = (int) abs($offset);
        $sign    = $offset < 0 ? -1 : 1;
        $hours   = (int) floor($seconds / 3600.0);
        $minutes = ($seconds / 60) - ($hours * 60);

        return sprintf('%+d:%02d', $hours * $sign, $minutes);
    }
}
