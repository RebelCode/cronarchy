<?php

namespace RebelCode\Cronarchy;

use DateTime;
use Exception;
use RuntimeException;

/**
 * The main class for cronarchy, comprised of a job manager, a daemon runner and a config manager.
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
     * @var Runner
     */
    protected $runner;

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
     * @param string     $instanceId The instance ID. Must be unique.
     * @param JobManager $manager    The job manager instance.
     * @param Runner     $runner     The daemon runner instance.
     * @param Config     $config     The config instance.
     */
    public function __construct($instanceId, JobManager $manager, Runner $runner, Config $config)
    {
        $this->instanceId = $instanceId;
        $this->manager    = $manager;
        $this->runner     = $runner;
        $this->config     = $config;
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
     * @return Runner
     */
    public function getRunner()
    {
        return $this->runner;
    }

    /**
     * Retrieves the config instance.
     *
     * @since [*next-version*]
     *
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
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
            if (defined('DOING_CRON') && DOING_CRON) {
                return;
            }
            $this->getRunner()->runDaemon();
        });
    }

    /**
     * Creates a new cronarchy instance.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId An ID for the instance. Must be unique site-wide.
     * @param string $daemonUrl  The absolute URL to the daemon file.
     * @param string $configFile The path to the daemon config file, which will be created if it does not exist.
     * @param array  $config     Initial config to save to the config file if it does not exist.
     *
     * @throws Exception If an error occurs.
     *
     * @return Cronarchy The created instance.
     */
    public static function setup($instanceId, $daemonUrl, $configFile, $config = [])
    {
        global $wpdb;

        $jobsTable = new Table($wpdb, sprintf('%s_jobs', $instanceId), static::getJobsTableSql());
        $jobsTable->init();
        $jobsTable->query("SET time_zone='%s';", [static::getJobsTableTimezone()]);

        $config = new Config($configFile, $config);
        try {
            $config->load();
        } catch (RuntimeException $exception) {
            $config->save();
        }

        $manager  = new JobManager($instanceId, $jobsTable);
        $runner   = new Runner($instanceId, $daemonUrl, $config);
        $instance = new self($instanceId, $manager, $runner, $config);
        $instance->init();

        return $instance;
    }

    /**
     * Retrieves the jobs table creation SQL.
     *
     * @since [*next-version*]
     *
     * @return string
     */
    protected static function getJobsTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `{{table}}` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `hook` varchar(255) NOT NULL,
                `args` longtext NOT NULL,
                `timestamp` datetime NOT NULL,
                `recurrence` bigint DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;';
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
