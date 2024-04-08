<?php

namespace juvo\AS_Processor;

use SplQueue;

abstract class Sequential_Sync implements Syncable
{

    use Sync_Data;

    /**
     * Stores all of the sync tasks in a queue
     *
     * @var SplQueue|mixed
     */
    protected SplQueue $queue;

    /**
     * Contains the hook name if the current sync being executed
     *
     * @var null|Sync
     */
    protected ?Sync $current_sync;

    public function __construct() {
        $data = $this->get_sync_data();

        if (empty($data)) {
            $this->queue = new SplQueue();
        } else {
            $this->queue = $data['queue'];
            $this->current_sync = $data['current_sync'];

            // Add listener if current job is finished to execute next job
            add_action($this->current_sync->get_sync_name() . '_complete', [$this, 'next']);
        }
    }

    /**
     * Adds a task to the queue
     *
     * @param mixed $task Task to be added, could be a callback or any data type representing a task
     */
    public function enqueue(Sync $task): void
    {
        $this->queue->enqueue($task);
        $this->update_sync_data(['queue' => $this->queue]);
    }

    /**
     * Runs the next job in the queue
     */
    public function next(): void
    {

        // Add current jobs data to the queue
        if ($this->current_sync) {
            $this->update_sync_data([
                'jobs_data' => [
                    $this->current_sync->get_sync_group_name() => $this->current_sync->get_sync_data()
                ]
            ]);
        }

        // If queue is empty we either never started or we are done
        if ($this->queue->isEmpty()) {

            // If current sync is filled it means this is not the first call
            if ($this->current_sync) {

                // Reset data
                $this->current_sync = null;
                $this->update_sync_data([
                    'queue' => $this->queue,
                    'current_sync' => null
                ]);
                do_action($this->current_sync->get_sync_name() . '_complete');
            }
            return;
        }

        $sync = $this->queue->dequeue();
        $this->current_sync = $sync;

        // Save current queue back to db
        $this->update_sync_data([
            'queue' => $this->queue,
            'current_sync' => $this->current_sync
        ]);
    }

    /**
     * Returns the number of tasks in the queue
     *
     * @return int Number of tasks in the queue
     */
    public function get_queue_count(): int
    {
        return $this->queue->count();
    }

    /**
     * This function should be used to add the actual implementation of jobs.
     * Therefore, you should add all of the syncs to the queue in this function and use it to start the process
     *
     * @return void
     */
    abstract public function callback(): void;

}