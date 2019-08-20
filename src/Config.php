<?php

namespace RebelCode\Cronarchy;

use ArrayObject;
use RuntimeException;

/**
 * A config manager class for Cronarchy.
 *
 * The configuration is saved and loaded to and from a file on disk.
 * This is because the daemon needs to access this config prior to loading WordPress, when functions such as
 * `get_option()` or not available.
 *
 * @since 0.1
 */
class Config extends ArrayObject
{
    /**
     * The path to the config's save file.
     *
     * @since 0.1
     *
     * @var string
     */
    protected $saveFile;

    /**
     * Constructor.
     *
     * @since 0.1
     *
     * @param string $savePath The path to the config's save file.
     * @param array  $data     Config input data.
     */
    public function __construct($savePath, $data = [])
    {
        unset($data['wp_path']);
        $input = (!empty($data))
            ? array_merge($this->getDefaults(), $data)
            : [];
        parent::__construct($input);

        $this->setFilePath($savePath);
    }

    /**
     * Sets the path to the config save file.
     *
     * @since 0.1
     *
     * @param string $path The path to the config's save file.
     */
    protected function setFilePath($path)
    {
        if (empty($path)) {
            throw new RuntimeException('Cronarchy config save file path is invalid');
        }

        $this->saveFile = $path;
    }

    /**
     * Retrieves the default config values.
     *
     * @since 0.1
     *
     * @return array
     */
    protected function getDefaults()
    {
        return [
            'wp_path'            => defined('ABSPATH') ? ABSPATH : null,
            'run_interval'       => 10,
            'max_job_run_time'   => 60,
            'max_total_run_time' => 600,
            'delete_failed_jobs' => false,
            'self_pinging'       => false,
            'logging_enabled'    => false,
            'log_file_path'      => dirname($this->saveFile) . '/cronarchy.log',
        ];
    }

    /**
     * Saves the config to the save file.
     *
     * @since 0.1
     */
    public function save()
    {
        file_put_contents($this->saveFile, serialize($this->getArrayCopy()));
    }

    /**
     * Loads the config from the save file.
     *
     * @since 0.1
     *
     * @throws RuntimeException If the config save file does not exist or is not readable.
     */
    public function load()
    {
        if (!file_exists($this->saveFile) || !is_readable($this->saveFile)) {
            throw new RuntimeException('Cronarchy config save file path does not exist or is not readable');
        }

        $input = unserialize(file_get_contents($this->saveFile));
        $this->exchangeArray($input);
    }
}
