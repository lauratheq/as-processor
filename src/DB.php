<?php
/**
 * Handles the database.
 *
 * @package juvo\AS_Processor
 */

namespace juvo\AS_Processor;

/**
 * Database methods.
 */
trait DB
{
    /**
     * Chunks table name
     *
     * @var string
     */
    public string $table_name_chunks = 'asp_chunks';

    /**
     * Track if table has been checked
     *
     * @var boolean
     */
    private bool $table_checked = false;

    /**
     * Gets the table name for the chunks.
     *
     * @return string
     */
    public function get_chunks_table_name(): string
    {
        return $this->db()->prefix . $this->table_name_chunks;
    }

    /**
     * Gets the wpdb wrapper.
     *
     * @return \wpdb the WordPress database wrapper.
     */
    public function db(): \wpdb
    {
        global $wpdb;
        if (!$this->table_checked) {
            $this->maybe_create_table($wpdb);
            $this->table_checked = true;
        }
        return $wpdb;
    }

    /**
     * Check if table exists and create if not.
     *
     * @param \wpdb $wpdb the WordPress database wrapper.
     * @return void
     */
    private function maybe_create_table( \wpdb $wpdb ): void
    {
        $table_name = $wpdb->prefix . $this->table_name_chunks;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name text NOT NULL,
            status text NOT NULL,
            data longtext NOT NULL,
            start DECIMAL(20,6) DEFAULT NULL,
            end DECIMAL(20,6) DEFAULT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate}";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}