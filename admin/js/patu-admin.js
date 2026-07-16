/* Patu settings: the "test connection" button. */
( function () {
	var btn = document.getElementById( 'patu-test' );
	if ( ! btn ) {
		return;
	}
	var result = document.getElementById( 'patu-test-result' );
	var keyField = document.getElementById( 'patu_api_key' );

	btn.addEventListener( 'click', function () {
		result.textContent = PatuAdmin.i18n.testing;
		result.className = 'patu-test-result patu-testing';

		var body = new URLSearchParams();
		body.set( 'action', 'patu_test_connection' );
		body.set( 'nonce', PatuAdmin.nonce );
		if ( keyField && keyField.value ) {
			body.set( 'key', keyField.value );
		}

		fetch( PatuAdmin.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( j && j.success ) {
					result.textContent = j.data.message;
					result.className = 'patu-test-result patu-ok';
				} else {
					result.textContent = ( j && j.data && j.data.message ) || 'Error';
					result.className = 'patu-test-result patu-err';
				}
			} )
			.catch( function () {
				result.textContent = 'Request failed';
				result.className = 'patu-test-result patu-err';
			} );
	} );
} )();
