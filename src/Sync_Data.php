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

        $attempts = 0;
        do {
            try {

                if ($this->is_locked() && !$this->locked_by_current_process) {
                    throw new \Exception('Sync Data is locked');
                }

                $transient = $this->get_transient($this->get_sync_data_name());
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
            } catch (Exception $e) {
                $attempts++;
                sleep(1);
                continue;
            }
        } while($attempts < 5);

        $attempts = $attempts +1; // Adjust counting for final error
        throw new \Exception("Sync Data is locked. Tried {$attempts} times");
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
        $lock = $this->get_transient( $this->get_sync_data_name() . '_lock' );
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
        if ($this->locked_by_current_process) {
            return true;
        }

        return (bool) $this->get_transient( $this->get_sync_data_name() . '_lock' );
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
     * If a lock is set a wait of 1 second is set. After 5 failed tries a final error is thrown
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

        $attempts = 0;

        // Update sync data
        do {
            try {
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
                return;
            } catch (Exception $e) {
                $attempts++;
                sleep(1);
                continue;
            }
        } while($attempts < 5);

        // If this point is reached throw error
        throw new \Exception("Failed to update sync data after $attempts tries");
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
            if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                // If both arrays have the same key and values are arrays, we need to merge them
                if ($deepMerge) {
                    $array1[$key] = $this->mergeArrays($array1[$key], $value, $deepMerge, $concatArrays);
                } elseif ($concatArrays) {
                    $array1[$key] = $this->concatArraysPreserveKeys($array1[$key], $value);
                } else {
                    $array1[$key] = $value;
                }
            } else {
                // Otherwise, set the value from the second array
                $array1[$key] = $value;
            }
        }

        return $array1;
    }

    /**
     * Concatenate two arrays with keys
     *
     * This method concatenates two arrays with keys while preserving the keys and avoiding duplicates.
     * If a key is an integer, the corresponding value will be appended to the first array.
     * If a key is not an integer and exists in both arrays, and both values are arrays, the method will recursively merge them.
     * If a key is not an integer and exists in both arrays, but either value is not an array, the value from the second array will be set.
     *
     * @param array $array1 The first array to concatenate with
     * @param array $array2 The second array to concatenate
     * @return array The concatenated array
     */
    private function concatArraysPreserveKeys(array $array1, array $array2): array
    {
        foreach ($array2 as $key => $value) {
            if (is_int($key)) {
                // Append value to the array, preserving keys and avoiding duplicates
                if (!in_array($value, $array1, true)) {
                    $array1[] = $value;
                }
            } else {
                // If the key is not an integer, merge arrays if both values are arrays
                if (isset($array1[$key]) && is_array($array1[$key]) && is_array($value)) {
                    $array1[$key] = $this->concatArraysPreserveKeys($array1[$key], $value);
                } else {
                    // Otherwise, set the value from the second array
                    $array1[$key] = $value;
                }
            }
        }
        return $array1;
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
