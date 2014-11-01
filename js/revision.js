(function( $, Backbone ) {

	$(function() {
		postForking = window.postForking || {};
		postForking.view = postForking.view || {};

		postForking.settings = _.isUndefined( window._NYTPostForkingSettings ) ? {} : _NYTPostForkingSettings;

		postForking.view.Frame = wp.revisions.view.Frame.extend({
			initialize: function() {
				this.listenTo( this.model, 'update:diff', this.renderDiff );
				this.listenTo( this.model, 'change:compareTwoMode', this.updateCompareTwoMode );
				this.listenTo( this.model, 'change:loading', this.updateLoadingStatus );
				this.listenTo( this.model, 'change:error', this.updateErrorStatus );
			},
			render: function() {
				wp.Backbone.View.prototype.render.apply( this, arguments );

				$('html').css( 'overflow-y', 'scroll' );
				$('#postbox-container-2').append( this.el );
				this.updateCompareTwoMode();
				this.renderDiff( this.model.diff() );
				this.views.ready();

				return this;
			}
		});
		new postForking.view.Frame({
			model: new wp.revisions.model.FrameState({
				initialDiffState: {
					// wp_localize_script doesn't stringifies ints, so cast them.
					to: parseInt( postForking.settings.to, 10 ),
					from: parseInt( postForking.settings.from, 10 ),
					// wp_localize_script does not allow for top-level booleans so do a comparator here.
					compareTwoMode: ( postForking.settings.compareTwoMode === '1' )
				},
				diffData: postForking.settings.diffData,
				baseUrl: postForking.settings.baseUrl,
				postId: parseInt( postForking.settings.postId, 10 ),
				baseUrl: postForking.settings.baseUrl
			}, {
				revisions: new wp.revisions.model.Revisions( postForking.settings.revisionData )
			})
		}).render();
	});

})( jQuery, Backbone );