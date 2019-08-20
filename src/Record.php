<?php

namespace RebelCode\Cronarchy;

/**
 * Columns of jobs table.
 */
class Record
{

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $hook;

    /**
     * @var string
     */
    public $args;

    /**
     * @var string
     */
    public $timestamp;

    /**
     * @var int
     */
    public $recurrence;
}
