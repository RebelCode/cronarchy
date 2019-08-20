<?php

namespace RebelCode\Cronarchy;

/**
 * Represents a single job.
 *
 * @since 0.1
 */
class Job
{
    /**
     * The job's ID, or null if the job has not been saved to storage yet.
     *
     * @since 0.1
     *
     * @var int|null
     */
    protected $id;

    /**
     * The timestamp of the job's next run.
     *
     * @since 0.1
     *
     * @var int
     */
    protected $timestamp;

    /**
     * The WordPress hook that the job triggers when run.
     *
     * @since 0.1
     *
     * @var string
     */
    protected $hook;

    /**
     * The arguments to be passed to the triggered hook when the job is run.
     *
     * @since 0.1
     *
     * @var array
     */
    protected $args;

    /**
     * Optional amount of time that the job is re-scheduled with after it is run.
     *
     * @since 0.1
     *
     * @var int|null
     */
    protected $recurrence;

    /**
     * Constructor.
     *
     * @since 0.1
     *
     * @param int|null $id         The job's ID, or null if the job has not been saved to storage yet.
     * @param int      $timestamp  The timestamp of the job's next run.
     * @param string   $hook       The WordPress hook that the job triggers when run.
     * @param array    $args       The arguments to be passed to the triggered hook when the job is run.
     * @param int|null $recurrence Optional amount of time that the job is re-scheduled with after it is run, or null
     *                             if the job should only run once.
     */
    public function __construct($id, $timestamp, $hook, $args, $recurrence = null)
    {
        $this->id         = $id;
        $this->timestamp  = $timestamp;
        $this->hook       = $hook;
        $this->args       = $args;
        $this->recurrence = $recurrence;
    }

    /**
     * Retrieves the job's ID.
     *
     * @since 0.1
     *
     * @return int|null An integer ID, or null if the job has not been saved to storage yet.
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Retrieves the timestamp of the job's next run.
     *
     * @since 0.1
     *
     * @return int A unix timestamp.
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * Retrieves the hook that the job triggers when it is run.
     *
     * @since 0.1
     *
     * @return string The string name of the hook.
     */
    public function getHook()
    {
        return $this->hook;
    }

    /**
     * Retrieves the arguments to be passed to the triggered hook when the job is run.
     *
     * @since 0.1
     *
     * @return array A numeric array of argument values.
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * Retrieves the amount of time that the job is re-scheduled with after it is run.
     *
     * @since 0.1
     *
     * @return int|null The recurrence time in seconds or null if the job only runs once.
     */
    public function getRecurrence()
    {
        return $this->recurrence;
    }

    /**
     * Runs the job.
     *
     * @since 0.1
     */
    public function run()
    {
        do_action_ref_array($this->hook, $this->args);
    }
}
