/* PunchOut for WooCommerce, delivery review (0.4.20): a changed address or delivery method recalculates at once.
   Without scripting the update button ("Recalculate delivery", or "Update delivery" when no delivery cost is sent)
   does the same.
   0.4.22: the review form is sent once. After the first submission (Submit, "Back to cart", a
   recalculation or an added address) a second one is ignored and Submit and "Back to cart" are
   disabled, so a double click never sends the cart twice. They come back when the browser shows this page again
   from its history (the Back button). */
( function () {
	var choice = document.getElementById( 'pow-delivery-choice' );
	var form = choice ? choice.form : document.querySelector( '.pow-confirmation form' );
	if ( ! form ) { return; }
	var button = form.querySelector( 'button[name="pow_delivery_action"][value="review"]' );
	var guarded = Array.prototype.slice.call( form.querySelectorAll( 'button[name="pow_delivery_action"][value="submit"], button[name="pow_delivery_action"][value="back"]' ) );
	// The Submit button the server drew disabled (nothing to confirm yet) stays disabled when the others come back.
	var drawn = guarded.map( function ( control ) { return control.disabled; } );
	var status = document.createElement( 'p' );
	status.className = 'pow-confirmation__hint pow-confirmation__status';
	status.setAttribute( 'role', 'status' );
	if ( button ) { button.style.display = 'none'; button.parentNode.insertBefore( status, button ); }
	var sent = false;
	function busy( on ) {
		guarded.forEach( function ( control, index ) { control.disabled = on ? true : drawn[ index ]; } );
		if ( on ) { form.setAttribute( 'aria-busy', 'true' ); } else { form.removeAttribute( 'aria-busy' ); }
	}
	form.addEventListener( 'submit', function ( event ) {
		if ( sent ) { event.preventDefault(); return; }
		sent = true;
		// Disable only after this submission has collected its fields: a button disabled now would drop its own
		// name and value (the action) from the request.
		window.setTimeout( function () { busy( true ); }, 0 );
	} );
	// A page restored from the back/forward cache keeps its script state: let the buyer use the form again.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) { return; }
		sent = false;
		status.textContent = '';
		busy( false );
	} );
	function recalculate() {
		if ( sent ) { return; }
		status.textContent = button ? ( button.getAttribute( 'data-busy' ) || '' ) : '';
		form.setAttribute( 'aria-busy', 'true' );
		// requestSubmit fires the submit event above, which marks the form as sent.
		if ( form.requestSubmit && button ) { form.requestSubmit( button ); return; }
		sent = true;
		var action = document.createElement( 'input' );
		action.type = 'hidden'; action.name = 'pow_delivery_action'; action.value = 'review';
		form.appendChild( action ); form.noValidate = true; form.submit();
	}
	if ( choice ) { choice.addEventListener( 'change', function () { if ( choice.value ) { recalculate(); } } ); }
	form.querySelectorAll( 'input[type="radio"][name^="rates["]' ).forEach( function ( radio ) { radio.addEventListener( 'change', recalculate ); } );
} )();
