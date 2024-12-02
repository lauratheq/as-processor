<?php

namespace juvo\AS_Processor;

use DateTimeImmutable;

class Stats
{
    use DB;

    /**
     * The group name for the sync process.
     *
     * @var string
     */
    private string $group_name;

    /**
     * Stats constructor.
     *
     * @param string $group_name The group name for the sync process.
     */
    public function __construct(string $group_name) {
        $this->group_name = $group_name;
    }

    /**
     * Gets the sync duration.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return float|string|false Duration or false if not available.
     */
    public function get_sync_duration(bool $human_time = false): float|string|false {
        $query = $this->db()->prepare(
            "SELECT MIN(start) as sync_start, MAX(end) as sync_end 
            FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s",
            $this->group_name
        );

        $result = $this->db()->get_row($query);

        if (empty($result->sync_start) || empty($result->sync_end)) {
            return false;
        }

        $duration = round((float)$result->sync_end - (float)$result->sync_start, 4);

        if ($human_time) {
            return $this->human_time_diff_microseconds(0, $duration);
        }

        return $duration;
    }

    /**
     * Gets the total number of actions.
     *
     * @return int
     */
    public function get_total_actions(): int {
        $query = $this->db()->prepare(
            "SELECT COUNT(*) FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s",
            $this->group_name
        );

        return (int)$this->db()->get_var($query);
    }

    /**
     * Gets actions filtered by status.
     *
     * @param array|string $status Status to filter by.
     * @param bool $include_durations Whether to include durations.
     * @param bool $human_time Whether to return human-readable time.
     * @return array
     */
    public function get_actions_by_status(array|string $status, bool $include_durations = false, bool $human_time = false): array {
        $statuses = is_array($status) ? $status : [$status];
        $placeholders = array_fill(0, count($statuses), '%s');

        $query = $this->db()->prepare(
            "SELECT * FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s AND status IN (" . implode(',', $placeholders) . ")",
            array_merge([$this->group_name], $statuses)
        );

        $results = $this->db()->get_results($query, ARRAY_A);

        if ($include_durations) {
            foreach ($results as &$action) {
                if (!empty($action['start']) && !empty($action['end'])) {
                    $duration = round((float)$action['end'] - (float)$action['start'], 4);
                    $action['duration'] = $human_time ?
                        $this->human_time_diff_microseconds(0, $duration) :
                        $duration;
                }
            }
        }

        return $results;
    }

    /**
     * Gets the average action duration.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return float|string
     */
    public function get_average_action_duration(bool $human_time = false): float|string {
        $query = $this->db()->prepare(
            "SELECT AVG(end - start) as avg_duration 
            FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL",
            $this->group_name
        );

        $average = (float)$this->db()->get_var($query);

        return $human_time ?
            $this->human_time_diff_microseconds(0, $average) :
            $average;
    }

    /**
     * Gets the slowest action.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return array|null
     */
    public function get_slowest_action(bool $human_time = false): ?array {
        $query = $this->db()->prepare(
            "SELECT *, (end - start) as duration 
            FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration DESC 
            LIMIT 1",
            $this->group_name
        );

        $result = $this->db()->get_row($query, ARRAY_A);

        if (!$result) {
            return null;
        }

        $duration = round((float)$result['end'] - (float)$result['start'], 4);

        return [
            'id' => $result['id'],
            'duration' => $human_time ?
                $this->human_time_diff_microseconds(0, $duration) :
                $duration
        ];
    }

    /**
     * Gets the fastest action.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return array|null
     */
    public function get_fastest_action(bool $human_time = false): ?array {
        $query = $this->db()->prepare(
            "SELECT *, (end - start) as duration 
            FROM {$this->get_chunks_table_name()} 
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration ASC 
            LIMIT 1",
            $this->group_name
        );

        $result = $this->db()->get_row($query, ARRAY_A);

        if (!$result) {
            return null;
        }

        $duration = round((float)$result['end'] - (float)$result['start'], 4);

        return [
            'id' => $result['id'],
            'duration' => $human_time ?
                $this->human_time_diff_microseconds(0, $duration) :
                $duration
        ];
    }

    /**
     * Gets the sync start time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_start(): ?DateTimeImmutable {
        $query = $this->db()->prepare(
            "SELECT start 
        FROM {$this->get_chunks_table_name()} 
        WHERE `group` = %s 
        AND start IS NOT NULL 
        ORDER BY start ASC 
        LIMIT 1",
            $this->group_name
        );

        $start = $this->db()->get_var($query);

        if (empty($start)) {
            return null;
        }

        // Split microtime string into seconds and microseconds
        [$microseconds, $seconds] = explode(' ', $start);

        // Create datetime from unix timestamp and add microseconds
        return (new DateTimeImmutable('@' . $seconds))
            ->modify(sprintf('+%f seconds', $microseconds));
    }

    /**
     * Gets the sync end time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_end(): ?DateTimeImmutable {
        $query = $this->db()->prepare(
            "SELECT end 
        FROM {$this->get_chunks_table_name()} 
        WHERE `group` = %s 
        AND end IS NOT NULL 
        ORDER BY end DESC 
        LIMIT 1",
            $this->group_name
        );

        $end = $this->db()->get_var($query);

        if (empty($end)) {
            return null;
        }

        // Split microtime string into seconds and microseconds
        [$microseconds, $seconds] = explode(' ', $end);

        // Create datetime from unix timestamp and add microseconds
        return (new DateTimeImmutable('@' . $seconds))
            ->modify(sprintf('+%f seconds', $microseconds));
    }

    /**
     * Gets all actions for the current sync group.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     group: string,
     *     status: string
     * }>
     */
    public function get_actions(): array {
        $query = $this->db()->prepare(
            "SELECT id, name, `group`, status 
        FROM {$this->get_chunks_table_name()} 
        WHERE `group` = %s",
            $this->group_name
        );

        $results = $this->db()->get_results($query, ARRAY_A);

        if (!is_array($results)) {
            return [];
        }

        return array_map(
            static function(array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'group' => $row['group'],
                    'status' => $row['status']
                ];
            },
            $results
        );
    }

    /**
     * Get the object as a JSON string, including custom data.
     *
     * @param array $custom_data Optional custom data to be included
     * @return string
     */
    public function to_json(array $custom_data = []): string
    {
        $data = [
            'sync_start'              => $this->get_sync_start()?->format(DateTimeImmutable::ATOM),
            'sync_end'                => $this->get_sync_end()?->format(DateTimeImmutable::ATOM),
            'total_actions'           => $this->get_total_actions(),
            'sync_duration'           => $this->get_sync_duration(),
            'average_action_duration' => $this->get_average_action_duration(),
            'slowest_action'          => $this->get_slowest_action(),
            'fastest_action'          => $this->get_fastest_action(),
            'actions'                 => $this->get_actions(),
            'custom_data'             => $custom_data
        ];
        return json_encode($data);
    }

    /**
     * /**
     * Prepare an email text report, including custom data.
     *
     * @param array $custom_data Optional custom data to be included
     * @return string
     */
    public function prepare_email_text(array $custom_data = []): string
    {
        $email_text = "--- ". __("Synchronization Report:", 'as-processor') . " ---\n";
        $email_text .= sprintf(__("Sync Start: %s", 'as-processor'), $this->get_sync_start()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Sync End: %s", 'as-processor'), $this->get_sync_end()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Total Actions: %d", 'as-processor'), $this->get_total_actions()) . "\n";
        $email_text .= sprintf(__("Sync Duration: %s", 'as-processor'), $this->get_sync_duration(true)) . "\n";
        $email_text .= sprintf(__("Average Action Duration: %s", 'as-processor'), $this->get_average_action_duration(true)) . "\n";
        $email_text .= sprintf(__("Slowest Action Duration: %s", 'as-processor'), $this->get_slowest_action(true)['duration'] ?? __('N/A', 'as-processor')) . "\n";
        $email_text .= sprintf(__("Fastest Action Duration: %s", 'as-processor'), $this->get_fastest_action(true)['duration'] ?? __('N/A', 'as-processor')) . "\n";

        // Failed actions
        $failed_actions = $this->get_actions_by_status('failed');
        if (!empty($failed_actions)) {
            $email_text .= "\n-- " . __("Failed Actions Detail:", 'as-processor') . " --\n";
            foreach ($failed_actions as $action) {
                $email_text .= sprintf(__("Action ID: %s", 'as-processor'), $action['id']) . "\n";
                $email_text .= sprintf(__("Status: %s", 'as-processor'), $action['status']) . "\n";
                $email_text .= sprintf(__("Start: %s", 'as-processor'), $action['start']->format('Y-m-d H:i:s')) . "\n";
                if ($action['status'] === 'failed') {
                    $email_text .= sprintf(__("Error Message: %s", 'as-processor'), $action['error_message']) . "\n";
                }
                $email_text .= "\n";
            }
        }

        // Append custom data if available
        if (!empty($custom_data)) {
            $email_text .= "\n-- " . __("Custom Data:", 'as-processor') . " --\n";
            foreach ($custom_data as $key => $value) {
                $email_text .= sprintf(__("%s: %s", 'as-processor'), ucfirst($key), (is_array($value) ? json_encode($value) : $value)) . "\n";
            }
        }

        return $email_text;
    }

    /**
     * Calculates the human-readable time difference in microseconds between two given timestamps.
     *
     * @param float $from The starting timestamp.
     * @param float $to The ending timestamp. If not provided, the current timestamp will be used.
     * @return string The human-readable time difference in microseconds.
     */
    private function human_time_diff_microseconds( float $from, float $to = 0 ): string
    {
        if ( empty( $to ) ) {
            $to = microtime(true);
        }
        $diff = abs( $to - $from );

        $time_strings = array();

        if ( $diff < 1 ) { // Less than 1 second
            $total_microsecs = (int)($diff * 1000000);
            $millisecs = (int)($total_microsecs / 1000);
            $microsecs = $total_microsecs % 1000;

            if ( $millisecs > 0 ) {
                /* translators: Time difference in milliseconds */
                $time_strings[] = sprintf( _n( '%s millisecond', '%s milliseconds', $millisecs, 'as-processor' ), $millisecs );
            }
            if ( $microsecs > 0 ) {
                /* translators: Time difference in microseconds */
                $time_strings[] = sprintf( _n( '%s microsecond', '%s microseconds', $microsecs, 'as-processor' ), $microsecs );
            }
        } else {
            $remaining_seconds = $diff;

            $years = (int)($remaining_seconds / YEAR_IN_SECONDS);
            if ( $years > 0 ) {
                /* translators: Time difference in years */
                $time_strings[] = sprintf( _n( '%s year', '%s years', $years ), $years );
                $remaining_seconds -= $years * YEAR_IN_SECONDS;
            }

            $months = (int)($remaining_seconds / MONTH_IN_SECONDS);
            if ( $months > 0 ) {
                /* translators: Time difference in months */
                $time_strings[] = sprintf( _n( '%s month', '%s months', $months ), $months );
                $remaining_seconds -= $months * MONTH_IN_SECONDS;
            }

            $weeks = (int)($remaining_seconds / WEEK_IN_SECONDS);
            if ( $weeks > 0 ) {
                /* translators: Time difference in weeks */
                $time_strings[] = sprintf( _n( '%s week', '%s weeks', $weeks ), $weeks );
                $remaining_seconds -= $weeks * WEEK_IN_SECONDS;
            }

            $days = (int)($remaining_seconds / DAY_IN_SECONDS);
            if ( $days > 0 ) {
                /* translators: Time difference in days */
                $time_strings[] = sprintf( _n( '%s day', '%s days', $days ), $days );
                $remaining_seconds -= $days * DAY_IN_SECONDS;
            }

            $hours = (int)($remaining_seconds / HOUR_IN_SECONDS);
            if ( $hours > 0 ) {
                /* translators: Time difference in hours */
                $time_strings[] = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
                $remaining_seconds -= $hours * HOUR_IN_SECONDS;
            }

            $minutes = (int)($remaining_seconds / MINUTE_IN_SECONDS);
            if ( $minutes > 0 ) {
                /* translators: Time difference in minutes */
                $time_strings[] = sprintf( _n( '%s minute', '%s minutes', $minutes ), $minutes );
                $remaining_seconds -= $minutes * MINUTE_IN_SECONDS;
            }

            $seconds = (int)$remaining_seconds;
            if ( $seconds > 0 ) {
                /* translators: Time difference in seconds */
                $time_strings[] = sprintf( _n( '%s second', '%s seconds', $seconds ), $seconds );
                $remaining_seconds -= $seconds;
            }

            $milliseconds = (int)($remaining_seconds * 1000);
            if ( $milliseconds > 0 ) {
                /* translators: Time difference in milliseconds */
                $time_strings[] = sprintf( _n( '%s millisecond', '%s milliseconds', $milliseconds, 'as-processor' ), $milliseconds );
            }

            $microseconds = (int)($remaining_seconds * 1000000) - ($milliseconds * 1000);
            if ( $microseconds > 0 ) {
                /* translators: Time difference in microseconds */
                $time_strings[] = sprintf( _n( '%s microsecond', '%s microseconds', $microseconds, 'as-processor' ), $microseconds );
            }
        }

        // Join the time strings
        $separator = _x( ', ', 'Human time diff separator', 'as-processor' );
        return implode( $separator, $time_strings );
    }
}