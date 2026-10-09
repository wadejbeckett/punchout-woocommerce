/* PunchOut for WooCommerce, start link (0.4.23). The page's form is sent once: at load when the form says
   data-pow-start="auto", otherwise on the buyer's press. After that the button is disabled and any further submit is
   ignored, so a double click sends one POST. The page inlines this file and names it by hash in its policy. */
( function () {
	var form = document.getElementById( 'pow-start-form' );
	if ( ! form ) { return; }
	var button = form.querySelector( 'button' );
	var sent = false;
	function busy( on ) {
		if ( button ) { button.disabled = on; }
		if ( on ) { form.setAttribute( 'aria-busy', 'true' ); } else { form.removeAttribute( 'aria-busy' ); }
	}
	form.addEventListener( 'submit', function ( event ) {
		if ( sent ) { event.preventDefault(); return; }
		sent = true;
		// The button carries no name or value, so disabling it drops nothing from this submission.
		busy( true );
	} );
	// A page restored from the back/forward cache keeps its script state: let the buyer press again.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) { return; }
		sent = false;
		busy( false );
	} );
	if ( 'auto' === form.getAttribute( 'data-pow-start' ) ) {
		// submit() fires no submit event, so mark the form sent first.
		sent = true;
		busy( true );
		form.submit();
	}
} )();
