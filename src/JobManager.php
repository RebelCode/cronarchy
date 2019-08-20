<?php

namespace RebelCode\Cronarchy;

use Exception;
use OutOfRangeException;

/**
 * The job manager class.
 *
 * @since 0.1
 */
class JobManager
{
    /**
     * The jobs table.
     *
     * @since 0.1
     *
     * @var Table
     */
    protected $jobsTable;

    /**
     * The instance ID.
     *
     * @since 0.1
     *
     * @var string
     */
    protected $instanceId;

    /**
     * Constructor.
     *
     * @since 0.1
     *
     * @param string $instanceId The instance ID. Must be unique environment-wide.
     * @param Table  $jobsTable  The jobs table instance.
     */
    public function __construct($instanceId, Table $jobsTable)
    {
        $this->instanceId = $instanceId;
        $this->jobsTable  = $jobsTable;
    }

    /**
     * Retrieves the manager instance ID.
     *
     * @since 0.1
     */
    public function getInstanceId()
    {
        return $this->instanceId;
    }

    /**
     * Retrieves a single job by ID or the first job that matches a specific set of properties.
     *
     * @since 0.1
     *
     * @param int|null    $id         Optional ID of the job to retrieve.
     * @param int|null    $time       Optional timestamp to get only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to get only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to get only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to get only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @throws OutOfRangeException If no job with the given ID was found.
     * @throws Exception           If an error occurred while retrieving the job from the database.
     *
     * @return Job The job instance.
     */
    public function getJob($id, $time = null, $hook = null, $args = null, $recurrence = null)
    {
        $ids  = ($id === null) ? null : [$id];
        $rows = $this->getJobs($ids, $time, $hook, $args, $recurrence);

        if (count($rows) === 0) {
            throw new OutOfRangeException('No matching job was found');
        }

        return $rows[0];
    }

    /**
     * Retrieves scheduled jobs, optionally filtering them based on certain criteria.
     *
     * @since 0.1
     *
     * @param int[]|null  $ids        Optional list of IDs to get only jobs with any of those IDs.
     * @param int|null    $time       Optional timestamp to get only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to get only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to get only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to get only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @throws Exception If an error occurred while reading the jobs from storage.
     *
     * @return Job[] An array of {@link Job} instances.
     */
    public function getJobs($ids = null, $time = null, $hook = null, $args = null, $recurrence = null)
    {
        list($condition, $conditionArgs) = $this->buildJobCondition($ids, $time, $hook, $args, $recurrence);

        $records = $this->jobsTable->fetch($condition, $conditionArgs);
        $jobs    = array_map([$this, 'createJobFromRecord'], $records);

        return $jobs;
    }

    /**
     * Retrieves all scheduled jobs that are pending for execution.
     *
     * @since 0.1
     *
     * @throws Exception If an error occurred while retrieving jobs from the database.
     *
     * @return Job[] An array of {@link Job} instances.
     */
    public function getPendingJobs()
    {
        $records = $this->jobsTable->fetch('`timestamp` < NOW()');
        $jobs    = array_map([$this, 'createJobFromRecord'], $records);

        return $jobs;
    }

    /**
     * Schedules a job.
     *
     * If the job exists (determined by its ID), it is updated with the given job instance's data.
     * If the job does not exist or has a null ID, it is inserted.
     *
     * @since 0.1
     *
     * @param Job $job the job to schedule.
     *
     * @throws Exception If an error occurred while inserting the job into the database.
     *
     * @return int The scheduled job's ID.
     */
    public function scheduleJob(Job $job)
    {
        $data = [
            'timestamp'  => $this->tsToDatabaseDate($job->getTimestamp()),
            'hook'       => $job->getHook(),
            'args'       => serialize($job->getArgs()),
            'recurrence' => $job->getRecurrence(),
        ];
        $formats = [
            'timestamp'  => '%s',
            'hook'       => '%s',
            'args'       => '%s',
            'recurrence' => '%d',
        ];

        $id = $job->getId();

        if ($id === null) {
            return $this->jobsTable->insert($data, $formats);
        }

        $this->jobsTable->update($data, $formats, '`id` = %s', [$id]);

        return $id;
    }

    /**
     * Cancels the next invocation of a scheduled job and schedules the next invocation, if applicable.
     *
     * @since 0.1
     *
     * @param Job $job The job instance.
     *
     * @throws OutOfRangeException If no job exists in the database with the given ID.
     * @throws Exception           If an error occurred while cancelling the job in the database.
     *
     * @return Job|null The job instance for the next invocation, or null if no recurrence job was scheduled.
     */
    public function scheduleJobRecurrence(Job $job)
    {
        $recurrence = $job->getRecurrence();

        if ($recurrence === null || $recurrence < 1) {
            return null;
        }

        $newTime = time() + $recurrence;
        $newJob  = new Job(null, $newTime, $job->getHook(), $job->getArgs(), $recurrence);

        $this->scheduleJob($newJob);

        return $newJob;
    }

    /**
     * Deletes jobs from the database.
     *
     * @since 0.1
     *
     * @param int[]|null  $ids        Optional list of IDs to delete only jobs with any of those IDs.
     * @param int|null    $time       Optional timestamp to delete only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to delete only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to delete only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to delete only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @throws Exception If an error occurred while deleting the job in the database.
     */
    public function deleteJobs($ids = null, $time = null, $hook = null, $args = null, $recurrence = null)
    {
        list($condition, $conditionArgs) = $this->buildJobCondition($ids, $time, $hook, $args, $recurrence);

        $this->jobsTable->delete($condition, $conditionArgs);
    }

    /**
     * Creates a job instance from a database record.
     *
     * @since 0.1
     *
     * @param Record $record The database record.
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
     * @since 0.1
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
     * @since 0.1
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
     * @since 0.1
     *
     * @param int $timestamp The timestamp to transform.
     *
     * @return string The date string.
     */
    protected function tsToDatabaseDate($timestamp)
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Builds a condition to match jobs based on a combination of their properties.
     *
     * @since 0.1
     *
     * @param int[]|null  $ids        Optional list of IDs to match only the jobs with any of those IDs.
     * @param int|null    $time       Optional timestamp to match only jobs scheduled for this time.
     * @param string|null $hook       Optional hook name to match only jobs scheduled with this hook.
     * @param array|null  $args       Optional array of hook args to match only jobs with these hook args.
     * @param int|null    $recurrence Optional interval time to match only jobs with this recurrence.
     *                                Use zero to get jobs that do not repeat.
     *
     * @return array The build condition
     */
    protected function buildJobCondition($ids = null, $time = null, $hook = null, $args = null, $recurrence = null)
    {
        $cParts = [];
        $cArgs  = [];

        if ($ids !== null) {
            $cParts[] = '`id` IN (%s)';
            $cArgs[]  = implode(',', (array) $ids);
        }
        if ($time !== null) {
            $cParts[] = '`time` = "%s"';
            $cArgs[]  = $this->tsToDatabaseDate($time);
        }
        if ($hook !== null) {
            $cParts[] = '`hook` = "%s"';
            $cArgs[]  = $hook;
        }
        if ($args !== null) {
            $cParts[] = '`args` = "%s"';
            $cArgs[]  = $this->serializeArgs($args);
        }
        if ($recurrence !== null) {
            $cParts[] = ($recurrence === 0)
                ? '`recurrence` IS NULL'
                : '`recurrence` === %d';
            $cArgs[] = $recurrence;
        }

        $cStr = implode(' AND ', $cParts);

        return [$cStr, $cArgs];
    }
}
