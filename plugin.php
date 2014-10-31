<?php
/**
 * To do
 * 		Allow user to edit a revision and save it as a new revision.
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

	if ( $post->post_type === 'post' ) {
		$whitelisted_pages = array( 'post.php', 'post-new.php' );
		if ( ! in_array( $GLOBALS['hook_suffix'], $whitelisted_pages ) ) {
			return;
		}

		wp_enqueue_script( 'post-forking-post-edit',
			plugins_url( 'includes/js/post.js', __FILE__ ),
			array( 'jquery', 'backbone', 'post', 'wp-util' ) );
	}

	if ( $post->post_type === 'revision' ) {
		$whitelisted_pages = array( 'post.php' );
		if ( ! in_array( $GLOBALS['hook_suffix'], $whitelisted_pages ) ) {
			return;
		}

		wp_enqueue_script( 'post-forking-revision-edit',
			plugins_url( 'includes/js/revision.js', __FILE__ ),
			array( 'jquery', 'backbone', 'post', 'wp-util', 'revisions' ) );

		$parent_post_id = $post->post_parent;
		wp_localize_script( 'post-forking-revision-edit', '_NYTPostForkingSettings', nytpf_get_settings_for_revision_edit_js( $parent_post_id, $post->ID ) );
	}

});

/**
 * @todo  core should have this as a function.
 */
add_action( 'admin_footer-post.php', function() {
	global $post;
	if ( $post->post_type != 'revision' )
		return;
	?><script id="tmpl-revisions-frame" type="text/html">
	<div class="revisions-control-frame"></div>
	<div class="revisions-diff-frame"></div>
</script>

<script id="tmpl-revisions-buttons" type="text/html">
	<div class="revisions-previous">
		<input class="button" type="button" value="<?php echo esc_attr_x( 'Previous', 'Button label for a previous revision' ); ?>" />
	</div>

	<div class="revisions-next">
		<input class="button" type="button" value="<?php echo esc_attr_x( 'Next', 'Button label for a next revision' ); ?>" />
	</div>
</script>

<script id="tmpl-revisions-checkbox" type="text/html">
	<div class="revision-toggle-compare-mode">
		<label>
			<input type="checkbox" class="compare-two-revisions"
			<#
			if ( 'undefined' !== typeof data && data.model.attributes.compareTwoMode ) {
			 	#> checked="checked"<#
			}
			#>
			/>
			<?php esc_attr_e( 'Compare any two revisions' ); ?>
		</label>
	</div>
</script>

<script id="tmpl-revisions-meta" type="text/html">
	<# if ( ! _.isUndefined( data.attributes ) ) { #>
		<div class="diff-title">
			<# if ( 'from' === data.type ) { #>
				<strong><?php _ex( 'From:', 'Followed by post revision info' ); ?></strong>
			<# } else if ( 'to' === data.type ) { #>
				<strong><?php _ex( 'To:', 'Followed by post revision info' ); ?></strong>
			<# } #>
			<div class="author-card<# if ( data.attributes.autosave ) { #> autosave<# } #>">
				{{{ data.attributes.author.avatar }}}
				<div class="author-info">
				<# if ( data.attributes.autosave ) { #>
					<span class="byline"><?php printf( __( 'Autosave by %s' ),
						'<span class="author-name">{{ data.attributes.author.name }}</span>' ); ?></span>
				<# } else if ( data.attributes.current ) { #>
					<span class="byline"><?php printf( __( 'Current Revision by %s' ),
						'<span class="author-name">{{ data.attributes.author.name }}</span>' ); ?></span>
				<# } else { #>
					<span class="byline"><?php printf( __( 'Revision by %s' ),
						'<span class="author-name">{{ data.attributes.author.name }}</span>' ); ?></span>
				<# } #>
					<span class="time-ago">{{ data.attributes.timeAgo }}</span>
					<span class="date">({{ data.attributes.dateShort }})</span>
				</div>
			<# if ( 'to' === data.type && data.attributes.restoreUrl ) { #>
				<input  <?php if ( wp_check_post_lock( $post->ID ) ) { ?>
					disabled="disabled"
				<?php } else { ?>
					<# if ( data.attributes.current ) { #>
						disabled="disabled"
					<# } #>
				<?php } ?>
				<# if ( data.attributes.autosave ) { #>
					type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Autosave' ); ?>" />
				<# } else { #>
					type="button" class="restore-revision button button-primary" value="<?php esc_attr_e( 'Restore This Revision' ); ?>" />
				<# } #>
			<# } #>
		</div>
	<# if ( 'tooltip' === data.type ) { #>
		<div class="revisions-tooltip-arrow"><span></span></div>
	<# } #>
<# } #>
</script>

<script id="tmpl-revisions-diff" type="text/html">
	<div class="loading-indicator"><span class="spinner"></span></div>
	<div class="diff-error"><?php _e( 'Sorry, something went wrong. The requested comparison could not be loaded.' ); ?></div>
	<div class="diff">
	<# _.each( data.fields, function( field ) { #>
		<h3>{{ field.name }}</h3>
		{{{ field.diff }}}
	<# }); #>
	</div>
</script><?php
});

function nytpf_get_settings_for_revision_edit_js( $post, $selected_revision_id, $from = null ) {
	// Require revision low-level API functions.
	require ABSPATH . 'wp-admin/includes/revision.php';

	$post = get_post( $post );
	$authors = array();
	$now_gmt = time();


	// $revisions = wp_get_post_revisions( $post->ID, array( 'order' => 'ASC', 'check_enabled' => false ) );

	// Only use the revision supplied, because that's all we want to compare.
	$revisions = array(
		$post->ID => $post,
		$selected_revision_id => get_post( $selected_revision_id )
	);
	// If revisions are disabled, we only want autosaves and the current post.
	if ( ! wp_revisions_enabled( $post ) ) {
		foreach ( $revisions as $revision_id => $revision ) {
			if ( ! wp_is_post_autosave( $revision ) )
				unset( $revisions[ $revision_id ] );
		}
		$revisions = array( $post->ID => $post ) + $revisions;
	}

	$show_avatars = get_option( 'show_avatars' );

	cache_users( wp_list_pluck( $revisions, 'post_author' ) );

	$can_restore = current_user_can( 'edit_post', $post->ID );
	$current_id = false;

	foreach ( $revisions as $revision ) {
		$modified = strtotime( $revision->post_modified );
		$modified_gmt = strtotime( $revision->post_modified_gmt );
		if ( $can_restore ) {
			$restore_link = str_replace( '&amp;', '&', wp_nonce_url(
				add_query_arg(
					array( 'revision' => $revision->ID,
						'action' => 'restore' ),
						admin_url( 'revision.php' )
				),
				"restore-post_{$revision->ID}"
			) );
		}

		if ( ! isset( $authors[ $revision->post_author ] ) ) {
			$authors[ $revision->post_author ] = array(
				'id' => (int) $revision->post_author,
				'avatar' => $show_avatars ? get_avatar( $revision->post_author, 32 ) : '',
				'name' => get_the_author_meta( 'display_name', $revision->post_author ),
			);
		}

		$autosave = (bool) wp_is_post_autosave( $revision );
		$current = ! $autosave && $revision->post_modified_gmt === $post->post_modified_gmt;
		if ( $current && ! empty( $current_id ) ) {
			// If multiple revisions have the same post_modified_gmt, highest ID is current.
			if ( $current_id < $revision->ID ) {
				$revisions[ $current_id ]['current'] = false;
				$current_id = $revision->ID;
			} else {
				$current = false;
			}
		} elseif ( $current ) {
			$current_id = $revision->ID;
		}

		$revisions[ $revision->ID ] = array(
			'id'         => $revision->ID,
			'title'      => get_the_title( $post->ID ),
			'author'     => $authors[ $revision->post_author ],
			'date'       => date_i18n( __( 'M j, Y @ G:i' ), $modified ),
			'dateShort'  => date_i18n( _x( 'j M @ G:i', 'revision date short format' ), $modified ),
			'timeAgo'    => sprintf( __( '%s ago' ), human_time_diff( $modified_gmt, $now_gmt ) ),
			'autosave'   => $autosave,
			'current'    => $current,
			'restoreUrl' => $can_restore ? $restore_link : false,
		);
	}

	/*
	 * If a post has been saved since the last revision (no revisioned fields
	 * were changed), we may not have a "current" revision. Mark the latest
	 * revision as "current".
	 */
	if ( empty( $current_id ) ) {
		if ( $revisions[ $revision->ID ]['autosave'] ) {
			$revision = end( $revisions );
			while ( $revision['autosave'] ) {
				$revision = prev( $revisions );
			}
			$current_id = $revision['id'];
		} else {
			$current_id = $revision->ID;
		}
		$revisions[ $current_id ]['current'] = true;
	}

	// Now, grab the initial diff.
	$compare_two_mode = is_numeric( $from );
	if ( ! $compare_two_mode ) {
		$found = array_search( $selected_revision_id, array_keys( $revisions ) );
		if ( $found ) {
			$from = array_keys( array_slice( $revisions, $found - 1, 1, true ) );
			$from = reset( $from );
		} else {
			$from = 0;
		}
	}

	$from = absint( $from );

	$diffs = array( array(
		'id' => $from . ':' . $selected_revision_id,
		'fields' => wp_get_revision_ui_diff( $post->ID, $from, $selected_revision_id ),
	));

	return array(
		'postId'           => $post->ID,
		'nonce'            => wp_create_nonce( 'revisions-ajax-nonce' ),
		'revisionData'     => array_values( $revisions ),
		'to'               => $selected_revision_id,
		'from'             => $from,
		'diffData'         => $diffs,
		'baseUrl'          => parse_url( admin_url( 'revision.php' ), PHP_URL_PATH ),
		'compareTwoMode'   => absint( $compare_two_mode ), // Apparently booleans are not allowed
		'revisionIds'      => array_keys( $revisions ),
	);
}


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