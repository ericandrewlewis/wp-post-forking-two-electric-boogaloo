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
			wp.ajax.post( 'pf_create_post_fork', { postData: wp.autosave.getPostData() } );
		});
	});

})( jQuery, Backbone );