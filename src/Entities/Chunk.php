<?php
/**
 * Chunk Entity
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor\Entities;

use Exception;
use juvo\AS_Processor\DB;

/**
 * The chunk entity class
 */
class Chunk
{
    use DB;

    /**
     * @var int
     */
    private int $chunk_id;

    /**
     * @var int|null
     */
    private ?int $action_id = null;

    /**
     * @var string|null
     */
    private ?string $name = null;

    /**
     * @var string|null
     */
    private ?string $status = null;

    /**
     * @var array<mixed>|null
     */
    private ?array $data = null;

    /**
     * @var string|null
     */
    private ?string $start = null;

    /**
     * @var string|null
     */
    private ?string $end = null;

    /**
     * @var string|null
     */
    private ?string $duration = null;

    /**
     * @var bool
     */
    private bool $is_data_fetched = false;

    /**
     * Constructor
     *
     * @param int|null $chunk_id The chunk ID.
     */
    public function __construct( ?int $chunk_id = null ) {
        if ( empty( $chunk_id ) ) {
            $this->chunk_id = $this->create_chunk();
        }
        $this->chunk_id = $chunk_id;
    }

    /**
     * Creates a chunk database entry.
     *
     * @throws Exception if database returns errors.
     * @return int the chunk id.
     */
    protected function create_chunk(): int
    {
        $query = $this->db()->prepare(
            "INSERT INTO {$this->get_chunks_table_name()}
            (name, status, data, start, end)
            VALUES (NULL, NULL, NULL, NULL, NULL)"
        );
        $result = $this->db()->query($query);
        if ( false === $result ) {
            throw new Exception('Could not insert chunk data!');
        }
        $inserted_id = (int) $this->db()->insert_id;
        return $inserted_id;
    }

    /**
     * Fetches data from database
     *
     * @return void
     */
    private function fetch_data(): void {
        if ( $this->is_data_fetched ) {
            return;
        }

        $data_query = $this->db()->prepare(
            "SELECT * FROM {$this->get_chunks_table_name()} WHERE id = %d",
            $this->chunk_id
        );
        $data = $this->db()->get_row( $data_query );

        if ( $data ) {
            $this->action_id = (int) $data->action_id;
            $this->name     = $data->name;
            $this->status   = $data->status;
            $this->data     = unserialize( $data->data );
            $this->start    = $data->start;
            $this->end      = $data->end;
            $this->duration = $data->duration;
        }

        $this->is_data_fetched = true;
    }

    /**
     * Get the chunk ID
     *
     * @return int
     */
    public function get_chunk_id(): int {
        return $this->chunk_id;
    }

    /**
     * Get the action ID
     *
     * @return int
     */
    public function get_action_id(): int {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->action_id;
    }

    /**
     * Get the name
     *
     * @return string
     */
    public function get_name(): string {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->name;
    }

    /**
     * Get the status
     *
     * @return string
     */
    public function get_status(): string {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->status;
    }

    /**
     * Get the data
     *
     * @return array<mixed>
     */
    public function get_data(): array {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->data;
    }

    /**
     * Get the start time
     *
     * @return string
     */
    public function get_start(): string {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->start;
    }

    /**
     * Get the end time
     *
     * @return string
     */
    public function get_end(): string {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }
        return $this->end;
    }

    /**
     * Get the duration in seconds
     *
     * @return float
     */
    public function get_duration(): float {
        if ( ! $this->is_data_fetched ) {
            $this->fetch_data();
        }

        // If duration is not yet calculated, calculate it
        if ( empty( $this->duration ) && ! empty( $this->end ) && ! empty( $this->start ) ) {
            return $this->calculate_duration();
        }

        return (float) $this->duration;
    }

    /**
     * Get formatted duration in human-readable format
     *
     * @return string
     */
    public function get_formatted_duration(): string {
        $duration = $this->get_duration();

        if ( 0.0 === $duration ) {
            return '0s';
        }

        // Format with microseconds precision
        return sprintf( '%.6fs', $duration );
    }

    /**
     * Calculate duration between start and end time
     *
     * @return float Duration in seconds with microseconds precision
     */
    private function calculate_duration(): float {
        if ( empty( $this->end ) || empty( $this->start ) ) {
            return 0.0;
        }

        $end_time   = (float) $this->end;
        $start_time = (float) $this->start;

        $duration = $end_time - $start_time;

        // Store the calculated duration
        $this->duration = (string) $duration;

        return $duration;
    }

    /**
     * Update multiple chunk properties at once
     *
     * @param array{
     *     status?: string,
     *     start?: string,
     *     end?: string,
     *     action_id?: int,
     *     data?: array<mixed>
     * } $arguments The update arguments
     * @return void
     */
    public function update( array $arguments ): void {
        $update_args = [];

        // Handle data separately due to serialization
        if ( isset( $arguments['data'] ) ) {
            $this->data = $arguments['data'];
            $update_args['data'] = serialize( $arguments['data'] );
            unset( $arguments['data'] );
        }

        // Handle other fields
        $allowed_fields = array(
            'name'      => '%s',
            'status'    => '%s',
            'start'     => '%s',
            'end'       => '%s',
            'action_id' => '%d',
        );
        $allowed_fields = apply_filters( 'asp/chunks/allowed_fields', $allowed_fields );
        $allowed_fields = array_keys( $allowed_fields );
        foreach ( $allowed_fields as $field ) {
            if ( isset( $arguments[ $field ] ) ) {
                $this->{$field} = $arguments[ $field ];
                $update_args[ $field ] = $arguments[ $field ];
            }
        }

        if ( ! empty( $update_args ) ) {
            $this->update_chunk( $this->chunk_id, $update_args );
        }
    }

    /**
     * Updates the chunk status and timestamps in the database.
     *
     * @param int $chunk_id The chunk ID to update
     * @param array{
     *     name?: string,
     *     status?: string,
     *     start?: string,
     *     end?: string,
     *     action_id?: int
     * } $arguments The update arguments
     * @return void
     */
    protected function update_chunk( int $chunk_id, array $arguments = [] ): void {
        if ( ! $chunk_id ) {
            return;
        }
    
        $table_name = $this->get_chunks_table_name();
        $data = array();
        $formats = array();
    
        $allowed_fields = array(
            'name'      => '%s',
            'status'    => '%s',
            'start'     => '%s',
            'end'       => '%s',
            'action_id' => '%d',
        );
        $allowed_fields = apply_filters( 'asp/chunks/allowed_fields', $allowed_fields );
    
        foreach ( $allowed_fields as $field => $format ) {
            if ( isset( $arguments[ $field ] ) ) {
                $data[ $field ] = $arguments[ $field ];
                $formats[] = $format;
            }
        }
    
        if ( empty( $data ) ) {
            return;
        }
    
        $this->db()->update(
            $table_name,
            $data,
            array( 'id' => $chunk_id ),
            $formats,
            array( '%d' )
        );

        // since the chunk is updated, we need to ensure that the next
        // query loads the correct data.
        $this->is_data_fetched = false;
    }
}