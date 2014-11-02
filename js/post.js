(function( $, Backbone ) {

	$(function() {
		/**
		 * Ensure clicking buttons inside #submitpost don't trigger the submit event.
		 *
		 * See trac ticket #30035
		 */
		$('#submitpost').find( ':button' ).on( 'click.edit-post', function( event ) {
			$('form#post').off( 'submit.edit-post' );
		});

		$('.pf-create-fork').on( 'click', function() {
			$('#submitpost, #postforksdiv')
				.find( ':button, :submit, a.submitdelete, #post-preview' )
					.prop( 'disabled', true );
			$('#submitpost .spinner, #postforksdiv .spinner').show();
			wp.ajax.send( 'pf_create_post_fork',
				{
					data: {
						postData: wp.autosave.getPostData()
					},
					success: function( response ) {
						var revisionId = response.revision_id;
						window.location = 'post.php?post=' + revisionId + '&action=edit';
					}
				}
			);
		});
	});

})( jQuery, Backbone );