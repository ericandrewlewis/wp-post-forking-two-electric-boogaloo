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
	global $post;
	if ( ! post_type_supports( $post->post_type, 'forks' ) ) {
		return;
	}
	?><div class="misc-pub-section" style="text-align: center;">
		<button type="button" class="pf-create-fork button button-primary">Create a fork</button>
	</div><?php
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

		wp_enqueue_style( 'post-forking-revision-css', plugins_url( 'css/revision-edit.css', __FILE__ ) );
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
 * Add some post type support to the revisions post type
 * so a revision's post fields can be edited.
 */

add_action('init', function() {
	add_post_type_support( 'revision', 'title' );
	add_post_type_support( 'revision', 'editor' );
});

/**
 * Add forking post type support for posts.
 */

add_action('init', function() {
	add_post_type_support( 'post', 'forks' );
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
 *
 * @todo figure out how to delete merged forks.
 */
add_action( 'save_post_revision', function( $post_ID, $post, $update ) {
	$fork = $post;
	if ( $fork->post_status != 'publish' ) {
		return;
	}
	$original = get_post( $post->post_parent );
	$original->post_title = $fork->post_title;
	$original->post_content = $fork->post_content;
	$new = wp_update_post( $original );
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

/**
 * Add a meta box for post forks on the Edit Post page.
 */
add_action( 'add_meta_boxes', function( $post_type, $post ) {
	if ( ! post_type_supports( $post_type, 'forks' ) ) {
		return;
	}
	$forks = pf_get_post_forks( $post->ID );

	// We should aim to show the forks metabox only when there are forks.
	if ( ! empty( $forks ) ) {
		add_meta_box('postforksdiv', __('Forks'), 'pf_post_forks_meta_box', null, 'normal', 'core');
	}
}, 10 , 2 );

/**
 * Add a meta box for comparison on the Edit Fork page.
 */
add_action( 'add_meta_boxes', function( $post_type, $post ) {
	if ( $post->post_type != 'revision' || $post->post_status == 'inherit' ) {
		return;
	}
	add_meta_box('compareforktooriginaldiv', __('Compare changes'), '__return_true', null, 'normal', 'core');
}, 10 , 2 );

/**
 * The post forks meta box.
 *
 * @param  int $post_id
 */
function pf_post_forks_meta_box( $post_id ) {
	if ( ! $post = get_post( $post_id ) )
		return;

	// @todo decide what to do with this, from the revisions meta box callback.
	// $args array with (parent, format, right, left, type) deprecated since 3.6
	// if ( is_array( $type ) ) {
	// 	$type = ! empty( $type['type'] ) ? $type['type']  : $type;
	// 	_deprecated_argument( __FUNCTION__, '3.6' );
	// }

	$forks = pf_get_post_forks( $post->ID );

	?><div class="misc-pub-section" style="text-align: right;">
		<span class="spinner"></span>
		<button type="button" class="pf-create-fork button button-primary">Create a fork</button>
	</div><?php

	if ( ! empty( $forks ) ) {
		$rows = '';
		foreach ( $forks as $fork ) {
			if ( ! current_user_can( 'read_post', $fork->ID ) )
				continue;

			$rows .= "\t<li>" . wp_post_revision_title_expanded( $fork ) . "</li>\n";
		}

		echo "<div class='hide-if-js'><p>" . __( 'JavaScript must be enabled to use this feature.' ) . "</p></div>\n";

		echo "<ul class='post-forks hide-if-no-js'>\n";
		echo $rows;
		echo "</ul>";
	} else {
		_e( 'No forks for this post yet.' );
	}
}

/**
 * Get post forks.
 *
 * @param  integer $post_id
 * @return array
 */
function pf_get_post_forks( $post_id = 0 ) {
	$post = get_post( $post_id );
	if ( ! $post || empty( $post->ID ) )
		return array();

	$post_stati = array_values( get_post_stati() );
	unset( $post_stati[ array_search( 'inherit', $post_stati ) ] );
	unset( $post_stati[ array_search( 'publish', $post_stati ) ] );

	$args = array( 'post_type' => 'revision', 'post_parent' => $post->ID, 'post_status' => $post_stati );

	if ( ! $forks = get_children( $args ) )
		return array();

	return $forks;
}

/**
 * When a fork is published, redirect to the original post's edit page.
 */
add_action( 'redirect_post_location', function( $location, $post_id ) {
	$post = get_post( $post_id );
	if ( $post->post_type != 'revision' || $post->post_status != 'publish' ) {
		return $location;
	}
	return add_query_arg( 'message', 11221, get_edit_post_link( $post->post_parent, 'link' ) );
}, 10, 2 );

/**
 * Add a message to alert user when a fork has been mergd.
 */
add_filter( 'post_updated_messages', function( $messages ) {
	$messages['post'][11221] = __( 'Fork merged.', 'post-forking' );
	return $messages;
} );

add_action( 'post_submitbox_misc_actions', function() {
	global $post;
	if ( $post->post_type != 'revision' || $post->post_status == 'inherit' ) {
		return;
	}
	$fork = $post;
	$original_post = get_post( $fork->post_parent );
	?><div class="misc-pub-section"><?php
	printf( __( 'This is a fork of %s.' ),
		sprintf( '<a href="%s">%s</a>',
			get_edit_post_link( $original_post->ID, 'link' ),
			$original_post->post_title ) );
	?></div>
	<?php // @todo figure out if having two IDs on the same page is bad.
		  // it probably is for some browsers. ?>
	<div class="pf-publishing-actions">
	<div class="pf-publishing-action">
	<span class="spinner"></span>
			<input type="submit" name="publish" class="button button-primary button-large" value="<?php _e( 'Merge fork' ) ?>" accesskey="p"></div>
	<div class="clear"></div>
	</div><?php
});

add_filter( 'admin_title', function( $admin_title, $title ) {
	global $post;
	if ( empty( $post ) || $post->post_type != 'revision' || $post->post_status == 'inherit' ) {
		return $admin_title;
	}
	$admin_title = __( 'Edit fork' );
	return $admin_title;
}, 10, 2 );

/**
 * Hacky filter choice to change the 'Edit Post' title.
 */
add_filter( 'post_updated_messages', function( $messages ) {
	global $title, $post;
	if ( $post->post_type != 'revision' || $post->post_status == 'inherit' ) {
		return $messages;
	}
	$title = __( 'Edit fork' );
	return $messages;
}, 10, 2 );