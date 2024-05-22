<?php

namespace juvo\AS_Processor;

use Exception;

trait Sync_Data
{

    private string $sync_data_name;
    private bool $locked_by_current_process = false;

    /**
     * Returns the sync data from a transient
     *
     * @param string $key
     * @return mixed
     * @throws \Exception
     */
    protected function get_sync_data(string $key = ""): mixed
    {
        if ($this->is_locked() && !$this->locked_by_current_process) {
            throw new \Exception('Sync Data is locked');
        }

        // Delete the transient from the object cache
        $this->clear_caches($this->get_sync_data_name());

        $transient = get_transient($this->get_sync_data_name());
        if (empty($transient)) {
            return false;
        }

        if (empty($key)) {
            return $transient;
        }

        if (isset($transient[$key])) {
            return $transient[$key];
        }

        return false;
    }

    /**
     * Attempts to acquire a lock and returns true if successful.
     *
     * @throws Exception If the lock is already acquired.
     *
     * @return bool True if the lock was acquired, false otherwise.
     */
    protected function acquire(int $lock_ttl = 5*MINUTE_IN_SECONDS): bool
    {

        // Delete the transient from the object cache
        $this->clear_caches($this->get_sync_data_name() . '_lock');

        $lock = get_transient( $this->get_sync_data_name() . '_lock' );
        if ($lock) {
            throw new Exception( 'Lock is already acquired' );
        }
        set_transient( $this->get_sync_data_name() . '_lock', true, $lock_ttl );
        $this->locked_by_current_process = true;
        return true;
    }

    /**
     * Releases a lock that was previously acquired.
     *
     * @return void
     */
    protected function release(): void
    {
        delete_transient( $this->get_sync_data_name() . '_lock' );
        $this->locked_by_current_process = false;
    }

    /**
     * Checks if a lock is currently held.
     *
     * @return bool True if the lock is held, false otherwise.
     */
    protected function is_locked(): bool
    {
        // Delete the transient from the object cache
        $this->clear_caches($this->get_sync_data_name() . '_lock');

        if ($this->locked_by_current_process) {
            return true;
        }

        return (bool) get_transient( $this->get_sync_data_name() . '_lock' );
    }

    /**
     * Returns the currently set sync data name. Defaults to the sync group name.
     * Since the name can be overwritten with the setter and the group name is retrieved from the "action_scheduler_before_execute"
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
     * @param array $data
     * @param int $expiration
     * @return void
     * @throws Exception
     */
    protected function set_sync_data(array $data, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        if ($this->is_locked() && !$this->locked_by_current_process) {
            throw new \Exception('Sync Data is locked');
        }

        set_transient($this->get_sync_data_name(), $data, $expiration);
    }

    /**
     * Updates parts of the transient data.
     *
     * This method updates the transient data by merging the provided updates into the current data.
     * It supports options for deep merging and array concatenation.
     *
     * Process:
     * - Acquires a lock to ensure data consistency.
     * - Retrieves the current transient data.
     * - Merges the updates into the current data based on the provided flags.
     * - Saves the updated data back into the transient storage.
     * - Releases the lock.
     *
     * @param array $updates Associative array of data to update.
     * @param int $expiration Optional. Expiration time in seconds. Default is 6 hours.
     * @param bool $deepMerge Optional. Flag to control deep merging. Default is true.
     *                        - true: Recursively merge nested arrays.
     *                        - false: Replace nested arrays instead of merging.
     * @param bool $concatArrays Optional. Flag to control array concatenation. Default is false.
     *                           - true: Concatenate arrays instead of replacing.
     *                           - false: Replace arrays instead of concatenating.
     * @return void
     * @throws Exception
     */
    protected function update_sync_data(array $updates,bool $deepMerge = false, bool $concatArrays = false, int $expiration = HOUR_IN_SECONDS * 6): void
    {
        // Lock data first
        $this->acquire();

        // Retrieve the current transient data.
        $currentData = $this->get_sync_data();

        // If there's no existing data, treat it as an empty array.
        if (!is_array($currentData)) {
            $currentData = [];
        }

        // Merge the new updates into the current data, respecting the deepMerge and concatArrays flags.
        $newData = $this->mergeArrays($currentData, $updates, $deepMerge, $concatArrays);

        // Save the updated data back into the transient.
        $this->set_sync_data($newData, $expiration);

        // Unlock
        $this->release();
    }

    /**
     * Recursively merges two arrays.
     *
     * This function provides options for both deep merging and array concatenation.
     * When deep merging is enabled, nested arrays are recursively merged. When
     * array concatenation is enabled, arrays are concatenated instead of being
     * replaced.
     *
     * @param array $array1 The original array.
     * @param array $array2 The array to merge into the original array.
     * @param bool $deepMerge Optional. Flag to control deep merging. Default is true.
     *                        - true: Recursively merge nested arrays.
     *                        - false: Replace nested arrays instead of merging.
     * @param bool $concatArrays Optional. Flag to control array concatenation. Default is false.
     *                           - true: Concatenate arrays instead of replacing.
     *                           - false: Replace arrays instead of concatenating.
     * @return array The merged array.
     *
     * @example
     * // Example Data
     * $currentData = [
     *     'post_ids' => [
     *         0 => 59576,
     *         1 => 59578,
     *         2 => 59579,
     *         3 => 59581,
     *         4 => 59583,
     *     ],
     *     'user_data' => [
     *         'name' => 'John',
     *         'roles' => ['admin', 'editor']
     *     ]
     * ];
     *
     * $updates = [
     *     'post_ids' => [
     *         0 => 59474,
     *         1 => 59475,
     *         2 => 59476,
     *         3 => 59477,
     *         4 => 59478,
     *         5 => 59479,
     *         6 => 59480,
     *         7 => 59481,
     *     ],
     *     'user_data' => [
     *         'name' => 'Jane',
     *         'roles' => ['subscriber']
     *     ]
     * ];
     *
     * // Deep merge with array concatenation
     * mergeArrays($currentData, $updates, true, true);
     * // Result:
     * // [
     * //     'post_ids' => [
     * //         0 => 59576,
     * //         1 => 59578,
     * //         2 => 59579,
     * //         3 => 59581,
     * //         4 => 59583,
     * //         5 => 59474,
     * //         6 => 59475,
     * //         7 => 59476,
     * //         8 => 59477,
     * //         9 => 59478,
     * //         10 => 59479,
     * //         11 => 59480,
     * //         12 => 59481,
     * //     ],
     * //     'user_data' => [
     * //         'name' => 'Jane',
     * //         'roles' => ['admin', 'editor', 'subscriber']
     * //     ]
     * // ]
     *
     * // Deep merge without array concatenation
     * mergeArrays($currentData, $updates, true, false);
     * // Result:
     * // [
     * //     'post_ids' => [
     * //         0 => 59474,
     * //         1 => 59475,
     * //         2 => 59476,
     * //         3 => 59477,
     * //         4 => 59478,
     * //         5 => 59479,
     * //         6 => 59480,
     * //         7 => 59481,
     * //     ],
     * //     'user_data' => [
     * //         'name' => 'Jane',
     * //         'roles' => ['subscriber']
     * //     ]
     * // ]
     *
     * // Shallow merge with array concatenation
     * mergeArrays($currentData, $updates, false, true);
     * // Result:
     * // [
     * //     'post_ids' => [
     * //         0 => 59576,
     * //         1 => 59578,
     * //         2 => 59579,
     * //         3 => 59581,
     * //         4 => 59583,
     * //         5 => 59474,
     * //         6 => 59475,
     * //         7 => 59476,
     * //         8 => 59477,
     * //         9 => 59478,
     * //         10 => 59479,
     * //         11 => 59480,
     * //         12 => 59481,
     * //     ],
     * //     'user_data' => [
     * //         'name' => 'Jane',
     * //         'roles' => ['subscriber']
     * //     ]
     * // ]
     *
     * // Shallow merge without array concatenation
     * mergeArrays($currentData, $updates, false, false);
     * // Result:
     * // [
     * //     'post_ids' => [
     * //         0 => 59474,
     * //         1 => 59475,
     * //         2 => 59476,
     * //         3 => 59477,
     * //         4 => 59478,
     * //         5 => 59479,
     * //         6 => 59480,
     * //         7 => 59481,
     * //     ],
     * //     'user_data' => [
     * //         'name' => 'Jane',
     * //         'roles' => ['subscriber']
     * //     ]
     * // ]
     */
    private function mergeArrays(array $array1, array $array2, bool $deepMerge = false, bool $concatArrays = false): array
    {
        foreach ($array2 as $key => $value) {
            if ($deepMerge && is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                // If concatenation is required, concatenate arrays
                if ($concatArrays) {
                    $array1[$key] = array_merge($array1[$key], $value);
                } else {
                    $array1[$key] = $this->mergeArrays($array1[$key], $value, $deepMerge, $concatArrays);
                }
            } else {
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

    /**
     * Clears caches for all the transients
     *
     * @param string $transient_name
     * @return void
     */
    private function clear_caches(string $transient_name): void
    {

        // Only continue if external cache is not used. External cache should cleanup after itself
        if (wp_using_ext_object_cache()) {
            return;
        }

        // Delete lock transient
        $transient_key = '_transient_' . $transient_name;
        wp_cache_delete($transient_key, 'options');

        // Delete lock timeout transient
        $transient_timeout_key = '_transient_timeout_' . $transient_name;
        wp_cache_delete($transient_timeout_key, 'options');
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