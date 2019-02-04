<?php

namespace RebelCode\Cronarchy;

use Exception;

/**
 * The main class for cronarchy, comprised of a job manager and a daemon runner.
 *
 * @since [*next-version*]
 */
class Cronarchy
{
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
     * @param JobManager   $manager The job manager instance.
     * @param DaemonRunner $runner  The daemon runner instance.
     */
    public function __construct(JobManager $manager, DaemonRunner $runner)
    {
        $this->manager = $manager;
        $this->runner = $runner;
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
        if (!(defined('DOING_CRON') && DOING_CRON)) {
            $this->getRunner()->runDaemon();
        }
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
     * @return Cronarchy The created instance.
     *
     * @throws Exception If an error occurs.
     */
    public static function setup($instanceId, $daemonUrl, $runInterval = 10, $maxRunTime = 600)
    {
        global $wpdb;

        $jobsTableName = sprintf('%s_jobs', $instanceId);
        $jobsTable = new Table(
            $wpdb,
            $jobsTableName,
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
        $manager = new JobManager($instanceId, $jobsTable);

        $filter = sprintf('cronarchy_get_%s_instance', $instanceId);
        $optionPrefix = sprintf('%s_', $instanceId);
        $runner = new DaemonRunner($daemonUrl, $filter, $optionPrefix, $runInterval, $maxRunTime);

        $instance = new self($manager, $runner);

        add_filter($filter, function () use ($instance) {
            return $instance;
        });

        return $instance;
    }
}
