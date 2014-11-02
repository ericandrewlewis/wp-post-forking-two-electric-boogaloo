<?php
/**
 * Plugin Name: Post Forking 2: Electric Boogaloo
 * Author: ericandrewlewis
 */
/**
 *
 * This can only run on trunk along with my work in trac ticket #30232.
 *
 * This plugin can't be used on a post with NYT Live Blogging 1.0 because that
 * unenqueues autosave, which this depends on.
 */


/**
 * Create a button in the the post Publish metabox to "Save as draft update".
 */
add_action( 'post_submitbox_misc_actions', function() {
	?><button type="button" class="pf-create-fork">Create a fork</button><?php
});

/**
 * Enqueue scripts.
 */
add_action( 'admin_enqueue_scripts', function() {
	global $wp_scripts, $post;
	if ( empty( $post ) )
		return;
	if ( $post->post_type === 'post' ) {
		$whitelisted_pages = array( 'post.php', 'post-new.php' );
		if ( ! in_array( $GLOBALS['hook_suffix'], $whitelisted_pages ) ) {
			return;
		}

		wp_enqueue_script( 'post-forking-post-edit',
			plugins_url( 'js/post.js', __FILE__ ),
			array( 'jquery', 'backbone', 'post', 'wp-util' ) );
	}

	if ( $post->post_type === 'revision' ) {
		$revision = $post;
		$whitelisted_pages = array( 'post.php' );
		if ( ! in_array( $GLOBALS['hook_suffix'], $whitelisted_pages ) ) {
			return;
		}

		wp_enqueue_script( 'post-forking-revision-edit',
			plugins_url( 'js/revision.js', __FILE__ ),
			array( 'jquery', 'backbone', 'post', 'wp-util', 'revisions' ) );

		$parent_post_id = $revision->post_parent;
		require_once( ABSPATH . 'wp-admin/includes/revision.php' );

		$latest_revision = current( wp_get_post_revisions( $parent_post_id, array( 'posts_per_page' => 1 ) ) );
		// In case the post has no revision (i.e. Hello World).
		// @todo this may not be necessary in the real world.
		if ( empty( $latest_revision ) ) {
			$latest_revision = get_post( $parent_post_id );
		}
		$revisions = array(
			$latest_revision->ID => $latest_revision,
			$revision->ID => $revision
		);
		wp_localize_script( 'post-forking-revision-edit',
			'_NYTPostForkingSettings',
			wp_prepare_revisions_for_js( array(
				'from' => $latest_revision->ID,
				'to' => $revision->ID,
				'post' => $parent_post_id,
				'revisions' => $revisions ) ) );
	}

});

/**
 * @todo  core should have this as a function.
 */
add_action( 'admin_footer-post.php', function() {
	global $post;
	if ( $post->post_type !== 'revision' ) {
		return;
	}
	$whitelisted_pages = array( 'post.php' );
	if ( ! in_array( $GLOBALS['hook_suffix'], $whitelisted_pages ) ) {
		return;
	}
	require_once( ABSPATH . 'wp-admin/includes/revision.php' );
	wp_print_revision_templates();
} );

/**
 * Add post type support to the revisions post type so revisions can be edited.
 */

add_action('init', function() {
	add_post_type_support( 'revision', 'editor' );
});

/**
 * Ajax callback to create a fork of a post.
 */
add_action( 'wp_ajax_pf_create_post_fork', function() {
	$post = $_REQUEST['postData'];
	$post['post_parent']   = $post['post_id'];
	$post['post_status']   = 'draft';
	$post['post_type']     = 'revision';
	$post['post_content']  = $post['content'];
	$post['post_name']     = "$post[post_id]-revision-v1"; // "1" is the revisioning system version
	$post['post_date']     = isset($post['post_modified']) ? $post['post_modified'] : '';
	$post['post_date_gmt'] = isset($post['post_modified_gmt']) ? $post['post_modified_gmt'] : '';
	$revision_id = wp_insert_post( $post );
	wp_send_json_success( array( 'revision_id' => $revision_id ) );
});

/**
 * WordPress sets drafts' 'post_date_gmt' to 0000 00:00... which messes up the temporal
 * direction of diffing a fork to its parent.
 *
 * Ensure revisions have proper post_date_gmt's set.
 *
 * See https://core.trac.wordpress.org/ticket/1837
 */
add_filter( 'wp_insert_post_data', function( $data, $postarr ) {
	if ( $data['post_type'] != 'revision' || $data['post_status'] === 'inherit' ) {
		return $data;
	}
	$data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
	return $data;
}, 10, 2 );

/**
 * When a revision is published, merge it into the original and delete the fork.
 */
add_action( 'save_post_revision', function( $post_ID, $post, $update ) {
	$fork = $post;
	if ( $fork->post_status != 'publish' ) {
		return;
	}
	$original = get_post( $post->post_parent );
	$original->post_content = $fork->post_content;
	$new = wp_update_post( $original );
	wp_delete_post( $fork->ID, true );
}, 10, 3 );

/**
 * Override the Edit Post link for forks.
 */
add_filter( 'get_edit_post_link', function( $link, $post_ID, $context ) {
	$post = get_post( $post_ID );
	// Short-circuit for non-revisions and WP revisions (non-forks).
	if ( $post->post_type != 'revision' || $post->post_status == 'inherit' ) {

		return $link;
	}

	$post_type_object = get_post_type_object( 'post' );

	if ( 'display' == $context )
		$action = '&amp;action=edit';
	else
		$action = '&action=edit';

	return sprintf( $post_type_object->_edit_link . $action, $post->ID );
}, 10, 3 );