<?php

namespace juvo\AS_Processor;

use ActionScheduler_Action;
use ActionScheduler_Store;

abstract class Sync implements Syncable
{

    use Sync_Data;

    private string $sync_group_name;

    public function __construct() {
        add_action('action_scheduler_begin_execute', function(int $action_id) {
            $this->maybe_trigger_last_in_group($action_id);
        }, 10, 1);

        add_action('action_scheduler_completed_action', [$this, 'maybe_trigger_last_in_group']);
        add_action($this->get_sync_name() . '/process_chunk', [$this, 'process_chunk']);

        // Set sync data key to the group name
        $this->sync_data_name = $this->get_sync_group_name();
    }

    /**
     * Contains the actual logic for the main task that should break the data into chunks.
     *
     * @return void
     */
    abstract function schedule(): void;

    /**
     * Returns the name of the sync. The name must always be deterministic.
     *
     * @return string
     */
    abstract function get_sync_name(): string;

    /**
     * Callback for the Chunk jobs
     *
     * @param array $data Data is wrapped in array to pass as single argument. Needed because of abstract child method enforcement
     * @return void
     */
    abstract function process_chunk(array $data): void;

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy
     *
     * @param \Generator $chunkData
     * @return void
     */
    abstract function process_chunk_data(\Generator $chunkData): void;

    /**
     * Returns the sync group name
     *
     * @return string
     */
    public function get_sync_group_name(): string
    {
        if (empty($this->sync_group_name)) {
            $this->sync_group_name = $this->get_sync_name() . '_'. time();
        }
        return $this->sync_group_name;
    }

    /**
     * Checks if there are more remaining jobs in the queue or if this is the last one.
     * This can be used to add additional cleanup jobs
     *
     * @param int $action_id
     * @return void
     */
    public function maybe_trigger_last_in_group(int $action_id): void
    {

        $action = $this->action_belongs_to_sync($action_id);
        if (!$action || empty($action->get_group())) {
            return;
        }

        $actions = as_get_scheduled_actions([
            'group' => $this->get_sync_group_name(),
            'status' => ActionScheduler_Store::STATUS_PENDING,
        ]);

        if (count($actions) === 0) {
            do_action($this->get_sync_name() . '_complete');
        }
    }

    /**
     * Schedules an async action to process a chunk of data
     *
     * @param array $data
     * @return void
     */
    protected function schedule_chunk(array $data): void
    {
        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [$data], // Wrap in array to pass as single argument. Needed because of abstract child method enforcement
            $this->get_sync_group_name()
        );
    }

    /**
     * Checks if the passed action belongs to the sync. If so returns the action object else false.
     *
     * @param int $action_id
     * @return false|ActionScheduler_Action
     */
    private function action_belongs_to_sync(int $action_id): false|ActionScheduler_Action
    {
        $action = ActionScheduler_Store::instance()->fetch_action((string) $action_id);

        // Action must contain the sync name as hook. Else it does not belong to sync
        if (!str_contains($action->get_hook(), $this->get_sync_name())) {
            return false;
        }

        // Set group name
        $this->sync_group_name = $action->get_group();

        return $action;
    }
}