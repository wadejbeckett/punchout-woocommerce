/* PunchOut for WooCommerce, delivery review (0.4.20): a changed address or delivery method recalculates at once.
   Without scripting the "Recalculate delivery" button does the same. */
( function () {
	var choice = document.getElementById( 'pow-delivery-choice' );
	var form = choice ? choice.form : document.querySelector( '.pow-confirmation form' );
	if ( ! form ) { return; }
	var button = form.querySelector( 'button[name="pow_delivery_action"][value="review"]' );
	var status = document.createElement( 'p' );
	status.className = 'pow-confirmation__hint pow-confirmation__status';
	status.setAttribute( 'role', 'status' );
	if ( button ) { button.style.display = 'none'; button.parentNode.insertBefore( status, button ); }
	var sent = false;
	function recalculate() {
		if ( sent ) { return; }
		sent = true;
		status.textContent = button ? ( button.getAttribute( 'data-busy' ) || '' ) : '';
		form.setAttribute( 'aria-busy', 'true' );
		if ( form.requestSubmit && button ) { form.requestSubmit( button ); return; }
		var action = document.createElement( 'input' );
		action.type = 'hidden'; action.name = 'pow_delivery_action'; action.value = 'review';
		form.appendChild( action ); form.noValidate = true; form.submit();
	}
	if ( choice ) { choice.addEventListener( 'change', function () { if ( choice.value ) { recalculate(); } } ); }
	form.querySelectorAll( 'input[type="radio"][name^="rates["]' ).forEach( function ( radio ) { radio.addEventListener( 'change', recalculate ); } );
} )();
