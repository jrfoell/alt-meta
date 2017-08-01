<?php
/**
 * Plugin Name: Alternative Meta Table Library
 * Description: Library to store data in alternative metadata tables.
 * Version:     1.0.0
 * Author:      WebDevStudios
 * Author URI:  https://webdevstudios.com
 * License:     GPLv2
 */

if ( ! class_exists( 'Alt_Meta' ) ) :

class Alt_Meta {

	protected $meta_type;
	protected $intercept_type;
	protected $wpdb;

	/**
	 * Construct and attach hooks.
	 * 
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @param string $meta_type      The new type of meta table, for wp_somethingmeta pass 'something'.
	 * @param string $intercept_type If you want your metadata to be attached to an existing object (post, user, etc.),
	 *                               pass that type here and additional hooks will be added.
	 */
	public function __construct( $meta_type, $intercept_type = null ) {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->meta_type = $meta_type;
		$this->intercept_type = $intercept_type;

		// Hooks.
		add_action( 'plugins_loaded', array( $this, 'register_metadata_table' ) );

		if ( $this->intercept_type ) {
			add_action( "add_{$this->intercept_type}_metadata", array( $this, 'intercept_add_metadata' ), 10, 5 );
			add_action( "update_{$this->intercept_type}_metadata", array( $this, 'intercept_update_metadata' ), 10, 5 );
			add_action( "delete_{$this->intercept_type}_metadata", array( $this, 'intercept_delete_metadata' ), 10, 5 );
			add_action( "get_{$this->intercept_type}_metadata", array( $this, 'intercept_get_metadata' ), 10, 4 );
			add_filter( 'pre_delete_post', array( $this, 'intercept_pre_delete_post' ), 99, 3 ); // Let others run before us.
		}
	}

	/**
	 * Create our custom metadata table.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://pippinsplugins.com/extending-wordpress-metadata-api/
	 */
	public function create_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$meta_table = "
		CREATE TABLE IF NOT EXISTS `{$this->wpdb->prefix}{$this->meta_type}meta` (
			`meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`{$this->meta_type}_id` bigint(20) unsigned NOT NULL DEFAULT '0',
			`meta_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
			`meta_value` longtext COLLATE utf8mb4_unicode_ci,
			PRIMARY KEY (`meta_id`),
			KEY `{$this->meta_type}_id` (`{$this->meta_type}_id`),
			KEY `meta_key` (`meta_key`(191))
		) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";

		dbDelta( $meta_table );
	}

	/**
	 * Register our custom metadata table with wpdb.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://pippinsplugins.com/extending-wordpress-metadata-api/
	 */
	public function register_metadata_table() {
		$this->wpdb->{$this->meta_type . 'meta'} = $this->wpdb->prefix . $this->meta_type . 'meta';
	}

	/**
	 * Intercept metadata add and run it on a custom table.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://codex.wordpress.org/Function_Reference/add_metadata
	 *
	 * @param null   $check      Input from filter, null if normal execution should happen.
	 * @param int    $object_id  ID of the object metadata is for.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value. Must be serializable if non-scalar.
	 * @param bool   $unique     Optional, default is false.
	 *                           Whether the specified metadata key should be unique for the object.
	 *                           If true, and the object already has a value for the specified metadata key,
	 *                           no change will be made.
	 * @return null|int|false    Null if normal execution should happen, the meta ID on success, false on failure.
	 */
	public function intercept_add_metadata( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		if ( ( $key_wo_prefix = $this->get_unprefixed_key( $meta_key ) ) !== false ) {
			return add_metadata( $this->meta_type, $object_id, $key_wo_prefix, $meta_value, $unique );
		}
		return $check;
	}

	/**
	 * Intercept metadata update and run it on a custom table. If no value already exists for the specified object
	 * ID and metadata key, the metadata will be added.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://codex.wordpress.org/Function_Reference/update_metadata
	 *
	 * @param null   $check      Input from filter, null if normal execution should happen.
	 * @param int    $object_id  ID of the object metadata is for.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value. Must be serializable if non-scalar.
	 * @param mixed  $prev_value Optional. If specified, only update existing metadata entries with
	 * 		                     the specified value. Otherwise, update all entries.
	 * @return null|int|false    Null if normal execution should happen, the meta ID on success, false on failure.
	 */
	public function intercept_update_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( ( $key_wo_prefix = $this->get_unprefixed_key( $meta_key ) ) !== false ) {
			return update_metadata( $this->meta_type, $object_id, $key_wo_prefix, $meta_value, $prev_value );
		}
		return $check;
	}

	/**
	 * Intercept metadata delete and run it on a custom table.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://codex.wordpress.org/Function_Reference/delete_metadata
	 *
	 * @param null   $check      Input from filter, null if normal execution should happen.
	 * @param int    $object_id  ID of the object metadata is for
	 * @param string $meta_key   Metadata key
	 * @param mixed  $meta_value Optional. Metadata value. Must be serializable if non-scalar. If specified, only delete
	 *                           metadata entries with this value. Otherwise, delete all entries with the specified meta_key.
	 *                           Pass `null, `false`, or an empty string to skip this check. (For backward compatibility,
	 *                           it is not possible to pass an empty string to delete those entries with an empty string
	 *                           for a value.)
	 * @param bool   $delete_all Optional, default is false. If true, delete matching metadata entries for all objects,
	 *                           ignoring the specified object_id. Otherwise, only delete matching metadata entries for
	 *                           the specified object_id.
	 * @return null|bool         Null if normal execution should happen, true on successful delete, false on failure.
	 */
	public function intercept_delete_metadata( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( ( $key_wo_prefix = $this->get_unprefixed_key( $meta_key ) ) !== false ) {
			return delete_metadata( $this->meta_type, $object_id, $key_wo_prefix, $meta_value, $delete_all );
		}
		return $check;
	}

	/**
	 * Intercept metadata retrieve and run it on a custom table.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://codex.wordpress.org/Function_Reference/get_metadata
	 *
	 * @param null   $check     Input from filter, null if normal execution should happen.
	 * @param int    $object_id ID of the object metadata is for
	 * @param string $meta_key  Optional. Metadata key. If not specified, retrieve all metadata for
	 * 		                    the specified object.
	 * @param bool   $single    Optional, default is false.
	 *                          If true, return only the first value of the specified meta_key.
	 *                          This parameter has no effect if meta_key is not specified.
	 * @return null|mixed       Null if normal execution should happen, otherwise single metadata value, or array of values.
	 */
	public function intercept_get_metadata( $check, $object_id, $meta_key = '', $single = false ) {
		if ( ( $key_wo_prefix = $this->get_unprefixed_key( $meta_key ) ) !== false ) {
			return get_metadata( $this->meta_type, $object_id, $key_wo_prefix, $meta_value, $delete_all );
		}
		return $check;
	}

	/**
	 * Deletes alternate metadata when the post it's associated with gets deleted.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @see https://developer.wordpress.org/reference/hooks/pre_delete_post/
	 *
	 * @param null    $check        Input from filter, null if normal execution should happen.
	 * @param WP_Post $post         Post object.
	 * @param bool    $force_delete Whether to bypass the trash.
	 */
	public function intercept_pre_delete_post( $check, $post, $force_delete ) {
		// Short circuit if we're not looking at an intercept type.
		if ( $post->post_type !== $this->intercept_type ) {
			return $check;
		}

		$meta_table = $this->wpdb->{$this->meta_type . 'meta'};
		$post_meta_ids = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT meta_id FROM {$meta_table} WHERE {$this->meta_type}_id = %d ", $post->ID ) );
		foreach ( $post_meta_ids as $mid ) {
			delete_metadata_by_mid( $this->meta_type, $mid );
		}
		return $check;
	}

	/**
	 * Return a meta key without a prefix if it starts with the prefix.
	 *
	 * @since 1.0.0
	 * @author Justin Foell <justin.foell@webdevstudios.com>
	 *
	 * @param string $meta_key Full meta key, may or may not be prefixed.
	 * @return string|bool     The meta key without the prefix, false if this meta key is not prefixed.
	 */
	private function get_unprefixed_key( $meta_key ) {
		if ( strpos( $meta_key, $this->meta_type . '_' ) === 0 ) {
			return substr( $meta_key, strlen( $this->meta_type ) + 1 );
		}
		return false;
	}
}

endif; // ! class_exists( 'Alt_Meta' ).
