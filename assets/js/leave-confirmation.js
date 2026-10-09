/* PunchOut for WooCommerce, Cancel and return asks first (0.5.0). A cancel control that names its dialog
   (data-pow-leave) opens that dialog instead of posting; only the dialog's own form carries the answer that leaves,
   and its "Keep shopping" closes it without a script (formmethod="dialog"). Where dialogs are not supported, or the
   dialog is missing, the control posts and the server asks instead (it sends the page back with the dialog open). */
( function () {
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var id = form && form.getAttribute ? form.getAttribute( 'data-pow-leave' ) : null;
		if ( ! id ) { return; }
		var dialog = document.getElementById( id );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) { return; }
		event.preventDefault();
		// Drawn open by the server (no script ran then): reopen it as a modal.
		if ( dialog.open ) { dialog.close(); }
		dialog.showModal();
	} );
} )();
