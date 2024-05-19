<?php

namespace juvo\AS_Processor;

trait Sync_Data
{

    private string $sync_data_name;

    /**
     * Returns the sync data from a transient
     *
     * @param string $key
     * @return mixed
     */
    protected function get_sync_data(string $key = ""): mixed
    {
        $transient = get_transient($this->get_sync_data_name());
        if (empty($key)) {
            return $transient;
        }

        if (isset($transient[$key])) {
            return $transient[$key];
        }

        return false;
    }

    /**
     * Returns the currently set sync data name. Defaults to the sync group name.
     * Since the name can be overwritten with the setter and the group name is retrieved from the "action_scheduler_before_execute"
     *
     *
     * @return string
     */
    public function get_sync_data_name(): string
    {
        // Set sync data key to the group name by default. Sequential Sync does not have a group name
        if (empty($this->sync_data_name)  && method_exists($this, 'get_sync_group_name')) {
            $this->sync_data_name = $this->get_sync_group_name();
            return $this->sync_data_name;
        }
        return $this->sync_data_name;
    }

    public function set_sync_data_name(string $sync_data_name): void
    {
        $this->sync_data_name = $sync_data_name;
    }

    /**
     * Stores data in a transient to be access in other jobs.
     * This can be used e.g. to build a delta of post ids
     *
     * @param $data
     * @param int $expiration
     * @return void
     */
    protected function set_sync_data($data, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        set_transient($this->get_sync_data_name(), $data, $expiration);
    }

    /**
     * Updates parts of the transient data.
     *
     * @param array $updates Associative array of data to update.
     * @param int $expiration Optional. Expiration time in seconds.
     * @return void
     */
    protected function update_sync_data(array $updates, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        // Retrieve the current transient data.
        $currentData = get_transient($this->get_sync_data_name());

        // If there's no existing data, treat it as an empty array.
        if (!is_array($currentData)) {
            $currentData = [];
        }

        // Merge the new updates into the current data.
        $newData = array_merge($currentData, $updates);

        // Save the updated data back into the transient.
        $this->set_sync_data($newData, $expiration);
    }

    /**
     * Fully deletes the sync data
     *
     * @return void
     */
    public function delete_sync_data(): void
    {
        delete_transient($this->get_sync_data_name());
    }
}