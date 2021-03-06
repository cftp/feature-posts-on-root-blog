<?php
 
/*  Copyright 2012 Code for the People Ltd

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

require_once( 'class-plugin.php' );

/**
 * 
 * 
 * @package Feature Posts on Root Blog
 * @author Code for the People Ltd
 **/
class FeaturePostsOnRootBlog extends FPORB_Plugin {

	/**
	 * A flag to say whether we're currently recursing, or not.
	 *
	 * @var type boolean
	 */
	public $recursing;

	/**
	 * A version number for cache busting, etc.
	 *
	 * @var type boolean
	 */
	public $version;

	/**
	 * Initiate!
	 *
	 * @return void
	 **/
	public function __construct() {
		$this->setup( 'fpotrb' );
		if ( is_admin() ) {
//			$this->add_action( 'admin_init' );
			$this->add_action( 'save_post', null, null, 2 );
			$this->add_action( 'load-post.php', 'load_post_edit' );
			$this->add_action( 'load-post-new.php', 'load_post_edit' );
		}
		$this->add_action( 'fporb_import_terms', 'process_import_terms' );
		$this->add_action( 'template_redirect' );
		$this->add_action( 'post_submitbox_misc_actions' );
		$this->add_filter( 'post_link', null, null, 2 );
		$this->add_filter( 'fporb_sync_meta_key', 'sync_meta_key', null, 2 );
		$this->add_filter( 'post_row_actions', null, 9999, 2 );
//		add_filter( 'fporb_promote', '__return_true' );
		
		$this->recursing = false;
		$this->version = 1;
	}
	
	// HOOKS AND ALL THAT
	// ==================

//	function admin_init() {
//		if ( is_main_site() ) 
//			return;
//				
//		if ( ! isset( $_GET[ 'SW_DO_PROMOTION' ] ) )
//			return;
//
//		$query = new WP_Query( array( 
//			'post_type' => 'post',
//			'post_status' => 'publish',
//			'fields' => 'ids',
//			'posts_per_page' => -1,
//		) );
//		$i = 0;
//		foreach ( $query->posts as & $post_id ) {
//			$i++;
//			update_post_meta( $post_id, '_fporb_promoted', true );
//			$post = get_post( $post_id );
//			$this->push_post_data_to_root( $post_id, & $post );
//			error_log( "Update $i of $post_id" );
//		}
//	}
	
	function load_post_edit() {
		if ( ! is_main_site() ) {
			wp_enqueue_script( 'fporb-admin', $this->url( '/js/admin.js' ), array( 'jquery' ), $this->version );
			$data = array(
				'this_site_only' => __( 'This site only', 'fporb' ), 
				'this_site_plus' => __( 'This site and the main site', 'fporb' ), 
			);
			wp_localize_script( 'fporb-admin', 'fporb', $data );
			wp_enqueue_style( 'fporb-admin', $this->url( '/css/admin.css' ), array(), $this->version );
			return;
		}
		
		$screen = get_current_screen();
		$post_id = isset( $_GET[ 'post' ] ) ? absint( $_GET[ 'post' ] ) : false;
		
		$this->process_import_terms( $post_id );
		
		if ( $orig_blog_id = get_post_meta( $post_id, '_fporb_orig_blog_id', true ) ) {
			$orig_post_id = get_post_meta( $post_id, '_fporb_orig_post_id', true );
			$blog_details = get_blog_details( array( 'blog_id' => $orig_blog_id ) );
			$edit_url = get_home_url( $orig_blog_id ) . '/wp-admin/post.php?action=edit&post=' . absint( $orig_post_id );
			$edit_link = '<a href="' . esc_url( $edit_url ) . '">' . __( 'edit post', 'fpotrb' ) . '</a>';
			$message = sprintf( __( 'Sorry, you must edit this post from the %1$s site: %2$s', 'fpotrb' ), $blog_details->blogname, $edit_link );
			wp_die( $message );
		}
	}
	
	function post_row_actions( $actions, $post ) {
		if ( ! is_main_site() )
			return $actions;
		if ( $orig_blog_id = get_post_meta( $post->ID, '_fporb_orig_blog_id', true ) ) {
			foreach ( $actions as $action_name => & $action ) {
				if ( 'view' != $action_name )
					unset( $actions[ $action_name ] );
			}
		}
		return $actions;
	}

	function post_submitbox_misc_actions() {
		if ( is_main_site() )
			return;

		global $post;
		
		// only want this behaviour on posts post type (filterable in future?)
		if ( $post->post_type != 'post' )
			return;
		
		$vars = array();
		$vars[ 'promoted' ] = get_post_meta( get_the_ID(), '_fporb_promoted', true );
		$this->render_admin( 'promote-meta-box-control.php', $vars );
	}
	
	/**
	 * Hooks the WP save_post action, fired after a post has been inserted/updated in the
	 * database, to duplicate the posts in the index site.
	 *
	 * @param int $orig_post_id The ID of the post being saved 
	 * @param object $orig_post A WP Post object of unknown type
	 * @return void
	 **/
	public function save_post( $orig_post_id, $orig_post ) {
		
		if ( is_main_site() )
			return;
		
		if ( 'post' != $orig_post->post_type )
			return;

		$promoted = false;
		if ( isset( $_POST[ '_fporb_status_nonce' ] ) ) {

			check_admin_referer( 'fporb_status_setting', '_fporb_status_nonce' );

			if ( isset( $_POST[ 'fporb-promotion' ] ) && $_POST[ 'fporb-promotion' ] ) {
				$promoted = true;
				update_post_meta( $orig_post_id, '_fporb_promoted', true );
			} else {
				delete_post_meta( $orig_post_id, '_fporb_promoted' );
			}

		}
		$promoted = apply_filters( 'fporb_promote', $promoted, $orig_post_id );
		if ( 'publish' == $orig_post->post_status && $promoted )
			$this->push_post_data_to_root( $orig_post_id, $orig_post );
		else
			$this->delete_post_from_root( $orig_post_id, $orig_post );
		
	}

	/**
	 * Hooks the WP post_link filter to provide the original
	 * permalink (stored in post meta) when a permalink
	 * is requested from the index blog.
	 *
	 * @param string $permalink The permalink
	 * @param object $post A WP Post object 
	 * @return string A permalink
	 **/
	public function post_link( $permalink, $post ) {
		global $blog_id;
		
		if ( ! is_main_site() )
			return $permalink;

		if ( $original_permalink = get_post_meta( $post->ID, '_fporb_permalink', true ) )
			return $original_permalink;
		
		return $permalink;
	}

	/**
	 * Hooks the fporb_sync_meta_key filter from this class which checks 
	 * if a meta_key should be synced. If we return false, it won't be.
	 *
	 * @param array $meta_keys The meta_keys which should be unsynced
	 * @return array The meta_keys which should be unsynced
	 **/
	function sync_meta_key( $sync, $meta_key ) {
		$sync_not = array(
			'_edit_last', // Related to edit lock, should be individual to translations
			'_edit_lock', // The edit lock, should be individual to translations
			'_bbl_default_text_direction', // The text direction, should be individual to translations
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
		);
		if ( in_array( $meta_key, $sync_not ) )
			$sync = false;
		return $sync;
	}

	function template_redirect() {
		if ( is_single() && $original_permalink = get_post_meta( get_the_ID(), '_fporb_permalink', true ) && is_main_site() ) {
			wp_redirect( $original_permalink, 301 );
			exit;
		}
	}
	
	// UTILITIES
	// =========

	function push_post_data_to_root( $orig_post_id, $orig_post ) {
		global $current_site, $current_blog;

		if ( $this->recursing )
			return;
		$this->recursing = true;

		// Get post data
		$orig_post_data = get_post( $orig_post_id, ARRAY_A );
		unset( $orig_post_data[ 'ID' ] );

		$orig_post_data = apply_filters( 'fporb_orig_post_data', $orig_post_data, $orig_post_id );
		
		// Get metadata
		$orig_meta_data = get_post_meta( $orig_post_id );
		foreach ( $orig_meta_data as $meta_key => $meta_rows ) {
			if ( ! $this->allow_sync_meta_key( $meta_key ) )
				unset( $orig_meta_data[ $meta_key ] );
		}
		// Note the following have to be one item arrays, to fit in with the
		// output of get_post_meta.
		$orig_meta_data[ '_fporb_permalink' ] = array( get_permalink( $orig_post_id ) );
		$orig_meta_data[ '_fporb_orig_post_id' ] = array( $orig_post_id );
		$orig_meta_data[ '_fporb_orig_blog_id' ] = array( $current_blog->blog_id );
		
		$orig_meta_data = apply_filters( 'fporb_orig_meta_data', $orig_meta_data, $orig_post_id );

		// Get terms
		$taxonomies = get_object_taxonomies( $orig_post );
		$orig_terms = array();
		foreach ( $taxonomies as $taxonomy ) {
			$orig_terms[ $taxonomy ] = array();
			$terms = wp_get_object_terms( $orig_post_id, $taxonomy );
			foreach ( $terms as & $term )
				$orig_terms[ $taxonomy ][ $term->slug ] = $term->name;
		}

		$orig_terms = apply_filters( 'fporb_orig_terms', $orig_terms, $orig_post_id );
		
		switch_to_blog( $current_site->blog_id );
		
		// Acquire ID and update post (or insert post and acquire ID)
		if ( $target_post_id = $this->get_root_blog_post_id( $orig_post_id, $current_blog->blog_id ) ) {
			$orig_post_data[ 'ID' ] = $target_post_id;
			wp_update_post( $orig_post_data );
		} else {
			$target_post_id = wp_insert_post( $orig_post_data );
		}

		// Delete all metadata
		$target_meta_data = get_post_meta( $target_post_id );
		foreach ( $target_meta_data as $meta_key => $meta_rows )
			delete_post_meta( $target_post_id, $meta_key );

		// Re-add metadata
		foreach ( $orig_meta_data as $meta_key => $meta_rows ) {
			$unique = ( count( $meta_rows ) == 1 );
			foreach ( $meta_rows as $meta_row )
				add_post_meta( $target_post_id, $meta_key, $meta_row, $unique );
		}

		// Set terms in the meta data, then schedule a Cron to come along and import them
		// We cannot import them here, as switch_to_blog doesn't affect taxonomy setup,
		// meaning we have the wrong taxonomies in the Global scope.
		update_post_meta( $target_post_id, '_orig_terms', $orig_terms );
		wp_schedule_single_event( time(), 'fporb_import_terms', array( $target_post_id ) );
		
		restore_current_blog();
		
		$this->recursing = false;
	}
	
	function process_import_terms( $target_post_id ) {
		if ( ! $orig_terms = get_post_meta( $target_post_id, '_orig_terms', true ) )
			return;
		foreach ( $orig_terms as $taxonomy => & $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) )
				continue;
			$target_terms = array();
			foreach ( $terms as $slug => $name ) {
				if ( $term = get_term_by( 'name', $name, $taxonomy ) ) {
					$term_id = $term->term_id;
				} else {
					$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
					if ( !is_wp_error( $result ) )
						$term_id = $result[ 'term_id' ];
					else 
						$term_id = 0;
				}
				$target_terms[] = absint( $term_id );
			}
			wp_set_object_terms( $target_post_id, $target_terms, $taxonomy );
		}
	}

	
	function delete_post_from_root( $orig_post_id, $orig_post ) {
		global $current_site, $current_blog;

		if ( $this->recursing )
			return;
		$this->recursing = true;

		switch_to_blog( $current_site->blog_id );
		
		// Acquire ID and update post (or insert post and acquire ID)
		if ( $target_post_id = $this->get_root_blog_post_id( $orig_post_id, $current_blog->blog_id ) )
			wp_delete_post ( $target_post_id, true );
		
		restore_current_blog();
		
		$this->recursing = false;
	}
	
	function get_root_blog_post_id( $orig_post_id, $orig_blog_id ) {
		$args = array(
			'post_type' => 'post',
			'post_status' => 'any',
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => '_fporb_orig_post_id',
					'value' => $orig_post_id,
					'type' => 'numeric'
				),
				array(
					'key' => '_fporb_orig_blog_id',
					'value' => $orig_blog_id,
					'type' => 'numeric',
				)
			),
		);
		$query = new WP_Query( $args );

		if ( $query->have_posts() )
			return $query->post->ID;

		return false;
	}
	
	function allow_sync_meta_key( $meta_key ) {
		// FIXME: Not now, but ultimately should this take into account Babble meta key syncing bans?
		return apply_filters( 'fporb_sync_meta_key', true, $meta_key );
	}
	
} // END UniversalTaxonomy class 

$feature_posts_on_root_blog = new FeaturePostsOnRootBlog();

