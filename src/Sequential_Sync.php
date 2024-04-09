<?php

namespace juvo\AS_Processor;

use SplQueue;

abstract class Sequential_Sync implements Syncable
{

    use Sync_Data;

    /**
     * Stores all the sync tasks in a queue
     *
     * @var SplQueue
     */
    private SplQueue $queue;

    /**
     * Contains the hook name if the current sync being executed
     *
     * @var ?Sync
     */
    private ?Sync $current_sync = null;

    /**
     * @var Sync[] Contains every job inside the queue as array.
     */
    private array $jobs = [];

    public function __construct() {
        $this->sync_data_name = $this->get_sync_name();

        $data = $this->get_sync_data();

        if (empty($data)) {
            $this->queue = new SplQueue();
        } else {

            // Restore data from run
            $this->queue = $data['queue'];
            $this->jobs = $data['jobs'] ?? [];
            $this->current_sync = $data['current_sync'] ?? null;

            if ($this->current_sync) {
                // Add listener if current job is finished to execute next job
                add_action($this->current_sync->get_sync_name() . '_complete', [$this, 'next']);
            }

            // Add hook callbacks
            $this->queue_init();
        }

        // Run the callback function once action is triggered to start the process
        add_action($this->get_sync_name(), [$this, 'callback']);

        // Delete sync data after sync is complete
        add_action($this->get_sync_name() . '_complete', [$this, 'delete_sync_data'], 999);
    }

    /**
     * Adds a task to the queue
     *
     * @param Sync $task Task to be added, could be a callback or any data type representing a task
     * @return bool
     */
    public function enqueue(Sync $task): bool
    {

        // Only enqueue new jobs if the queue did not start yet
        if($this->current_sync) {
            return false;
        }

        $task->set_sync_data_name($this->get_sync_name());

        $this->queue->enqueue($task);
        $this->update_sync_data([
            'queue' => $this->queue,
            'jobs' => iterator_to_array($this->queue)
        ]);

        return true;
    }

    /**
     * Starts the queue
     */
    public function start(): void
    {
        if($this->current_sync) {
            throw new \Exception("Sync already started");
        }

        $this->next();
    }

    /**
     * Runs the next job in the queue
     */
    public function next(): void
    {

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

                // Allow working on data after sync is complete
                do_action($this->get_sync_name() . '_complete');
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

        // Execute Sync
        do_action($this->current_sync->get_sync_name());
    }

    /**
     * Syncs/Jobs that are in a sequence are not instantiated on every page load, which is why their hook callbacks are not registered.
     * This function goes through all jobs and registers their hooks
     *
     * @return void
     */
    protected function queue_init(): void
    {
        foreach($this->jobs as $job) {
            $job->set_hooks();
        }
    }

    /**
     * This function should be used to add the actual implementation of jobs.
     * Therefore, you should add all of the syncs to the queue in this function and use it to start the process
     *
     * @return void
     */
    abstract public function callback(): void;

}