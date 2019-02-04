<?php

namespace RebelCode\Cronarchy;

use Exception;
use OutOfRangeException;

/**
 * The job manager class.
 *
 * @since [*next-version*]
 */
class JobManager
{
    /**
     * The jobs table.
     *
     * @since [*next-version*]
     *
     * @var Table
     */
    protected $jobsTable;

    /**
     * The instance ID.
     *
     * @since [*next-version*]
     *
     * @var int
     */
    protected $instanceId;

    /**
     * Constructor.
     *
     * @since [*next-version*]
     *
     * @param string $instanceId The instance ID. Must be unique environment-wide.
     * @param Table  $jobsTable  The jobs table instance.
     */
    public function __construct($instanceId, Table $jobsTable)
    {
        $this->instanceId = $instanceId;
        $this->jobsTable = $jobsTable;
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
     * Retrieves a job by ID.
     *
     * @since [*next-version*]
     *
     * @param int|null    $id         Optional ID of the job to retrieve.
     * @param int|null    $time       Optional timestamp to get only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to get only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to get only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to get only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @return Job The job instance.
     *
     * @throws OutOfRangeException If no job with the given ID was found.
     * @throws Exception If an error occurred while retrieving the job from the database.
     */
    public function getJob($id, $time = null, $hook = null, $args = null, $recurrence = null)
    {
        $rows = ($id === null)
            ? $this->getJobs($time, $hook, $args, $recurrence)
            : $this->jobsTable->fetch('`id` = %d', [$id]);

        if (count($rows) === 0) {
            throw new OutOfRangeException('No matching job was found');
        }

        $job = $this->createJobFromRecord($rows[0]);

        return $job;
    }

    /**
     * Retrieves scheduled jobs, optionally filtering them based on certain criteria.
     *
     * @since [*next-version*]
     *
     * @param int|null    $time       Optional timestamp to get only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to get only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to get only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to get only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @return Job[] An array of {@link Job} instances.
     *
     * @throws Exception If an error occurred while reading the jobs from storage.
     */
    public function getJobs($time = null, $hook = null, $args = null, $recurrence = null)
    {
        $conditionParts = [];

        if ($time !== null) {
            $conditionParts[] = sprintf('`time` = "%s"', $this->tsToDatabaseDate($time));
        }
        if ($hook !== null) {
            $conditionParts[] = sprintf('`hook` = "%s"', $hook);
        }
        if ($args !== null) {
            $conditionParts[] = sprintf('`args` = "%s"', $this->serializeArgs($args));
        }
        if ($recurrence !== null) {
            $conditionParts[] = ($recurrence === 0)
                ? sprintf('`recurrence` IS NULL')
                : sprintf('`recurrence` === %d', $recurrence);
        }

        $condition = implode(' AND ', $conditionParts);
        $records = $this->jobsTable->fetch($condition, []);

        return array_map([$this, 'createJobFromRecord'], $records);
    }

    /**
     * Retrieves all scheduled jobs that are pending for execution.
     *
     * @since [*next-version*]
     *
     * @return Job[] An array of {@link Job} instances.
     *
     * @throws Exception If an error occurred while retrieving jobs from the database.
     */
    public function getPendingJobs()
    {
        return array_map([$this, 'createJobFromRecord'], $this->jobsTable->fetch('`timestamp` < NOW()'));
    }

    /**
     * Schedules a job.
     *
     * If the job exists (determined by its ID), it is updated with the given job instance's data.
     * If the job does not exist or has a null ID, it is inserted.
     *
     * @since [*next-version*]
     *
     * @param Job $job the job to schedule.
     *
     * @return int The scheduled job's ID.
     *
     * @throws Exception If an error occurred while inserting the job into the database.
     */
    public function scheduleJob(Job $job)
    {
        $data = [
            'timestamp' => $this->tsToDatabaseDate($job->getTimestamp()),
            'hook' => $job->getHook(),
            'args' => serialize($job->getArgs()),
            'recurrence' => $job->getRecurrence(),
        ];
        $formats = [
            'timestamp' => '%s',
            'hook' => '%s',
            'args' => '%s',
            'recurrence' => '%d',
        ];

        $id = $job->getId();
        $exists = false;

        if ($id !== null) {
            try {
                $this->getJob($id);
                $exists = true;
            } catch (OutOfRangeException $exception) {
                // Ignore exception
            }
        }

        if (!$exists) {
            return $this->jobsTable->insert($data, $formats);
        }

        $this->jobsTable->update($data, $formats, '`id` = %s', [$id]);

        return $id;
    }

    /**
     * Cancels the next invocation of a scheduled job and schedules the following invocation, if applicable.
     *
     * @since [*next-version*]
     *
     * @param int $id The job ID.
     *
     * @throws OutOfRangeException If no job exists in the database with the given ID.
     * @throws Exception If an error occurred while cancelling the job in the database.
     */
    public function scheduleJobRecurrence($id)
    {
        $job = $this->getJob($id);

        $recurrence = $job->getRecurrence();

        if ($recurrence === null || $recurrence < 1) {
            return;
        }

        $newTime = $job->getTimestamp() + $recurrence;
        $newJob = new Job(null, $newTime, $job->getHook(), $job->getArgs(), $recurrence);

        $this->scheduleJob($newJob);
    }

    /**
     * Deletes a job from the database.
     *
     * @since [*next-version*]
     *
     * @param int $id The job ID.
     *
     * @throws OutOfRangeException If no job exists in the database with the given ID.
     * @throws Exception If an error occurred while deleting the job in the database.
     */
    public function deleteJob($id)
    {
        // To check if job exists and throw OutOfRangeException if not
        $this->getJob($id);

        $this->jobsTable->delete('`id` = %d', [$id]);
    }

    /**
     * Creates a job instance from a database record.
     *
     * @since [*next-version*]
     *
     * @param object $record The database record.
     *
     * @return Job The job instance.
     */
    protected function createJobFromRecord($record)
    {
        return new Job(
            $record->id,
            strtotime($record->timestamp),
            $record->hook,
            $this->unserializeArgs($record->args),
            $record->recurrence
        );
    }

    /**
     * Serializes a job's hook arguments.
     *
     * @since [*next-version*]
     *
     * @param array $args The hook arguments to serialize.
     *
     * @return string The serialization string.
     */
    protected function serializeArgs($args)
    {
        ksort($args, SORT_NUMERIC | SORT_ASC);
        $sArgs = serialize($args);

        return $sArgs;
    }

    /**
     * Serializes a job's hook arguments.
     *
     * @since [*next-version*]
     *
     * @param string $sArgs The serialized hook arguments to unserialize.
     *
     * @return array The unserialized arguments.
     */
    protected function unserializeArgs($sArgs)
    {
        $args = unserialize($sArgs);
        ksort($args, SORT_NUMERIC | SORT_ASC);

        return $args;
    }

    /**
     * Transforms a timestamp into a database date string.
     *
     * @since [*next-version*]
     *
     * @param int $timestamp The timestamp to transform.
     *
     * @return string The date string.
     */
    protected function tsToDatabaseDate($timestamp)
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
