<?php
/**
 * This plugin isn't even a plugin yet - doesn't even have a proper header.
 *
 * It can only run on trunk along with my work in trac ticket #30232.
 */


/**
 * Create a button in the the post Publish metabox to "Save as draft update".
 */
add_action( 'post_submitbox_misc_actions', function() {
	?><button type="button" class="pf-create-fork">Save as draft update</button><?php
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
		$revisions = array(
			$latest_revision->ID => get_post( $latest_revision ),
			$revision->ID => get_post( $revision->ID
		) );
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