<?php

namespace juvo\AS_Processor\Imports;

use Exception;
use juvo\AS_Processor\Import;

abstract class Fetch extends Import
{

    /**
     * The current index. Store either your offset or page number for the API call.
     *
     * @var int $current_index
     */
    protected int $current_index = 0;

    /**
     * The maximum index possible. Store either the maximum amount of items or pages.
     *
     * @var int $current_index
     */
    protected int $max_index = 0;

    /**
     * Sets the time between requests default = 1/4 sec
     *
     * @var int $time_between_requests
     */
    protected int $time_between_requests = 250;

    /**
     * The size of the chunks
     *
     * @var int $chunk_size
     */
    public int $chunk_size = 100;

    public function set_hooks(): void
    {
        parent::set_hooks();

        // register the hooks for the scheduled actions
        // first of the regular scheduler main action
        add_action( $this->get_sync_name(), [ $this, 'fetch' ] );
    }

    /**
     * Makes a call to the api
     *
     * @param int|null $index
     * @return void
     * @throws Exception
     */
    public function fetch(?int $index = null): void {

        // Maybe set current index. Default value can be set with class parameter in child implementation
        if ($index != null) {
            $this->current_index = $index;
        }

        $data = $this->process_fetch();

        if (empty($data)) {
            throw new Exception('No items received from the request');
        }

        // It is required that the developer sets the maximum index during the request implementation so we know when to end scheduling more requests
        if (empty($this->max_index)) {
            throw new Exception('You need to set the max index in you request implementation');
        }

        // Get the pending items added by other requests
        $existing_items = $this->get_sync_data('pending_items') ?: [];

        // Add current items to the pending items
        $items = array_merge($existing_items, $data);

        // If chunk size threshold is reached schedule the chunk.
        if (count($items) >= $this->chunk_size) {

            $this->schedule_chunk(array_slice($items, 0, $this->chunk_size));

            // Keep the remaining elements in the array
            $items = array_slice($items, $this->chunk_size);
        }

        // If this was the last request schedule chunk as well. Else schedule request
        if ($this->max_index === $this->current_index)  {
            $this->schedule_chunk($items);
        } else {

            // Get the current Unix timestamp with microseconds
            $microtime = microtime(true);

            // Add the milliseconds to the current time
            $newTime = $microtime + ($this->time_between_requests / 1000);

            // Schedule next request
            as_schedule_single_action( (int) $newTime, $this->get_sync_name(), [
                'index' => ++$this->current_index
            ], $this->get_sync_group_name() );
        }

        $this->update_sync_data(['pending_items' => $items]);
    }

    abstract protected function process_fetch(): mixed;
}
