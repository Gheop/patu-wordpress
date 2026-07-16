/* Patu bulk optimize / restore: fetch the id list, then walk it one at a time. */
( function () {
	var optimizeBtn = document.getElementById( 'patu-bulk-optimize' );
	var restoreBtn = document.getElementById( 'patu-bulk-restore' );
	var stopBtn = document.getElementById( 'patu-bulk-stop' );
	var bar = document.querySelector( '.patu-bulk-bar' );
	var fill = document.getElementById( 'patu-bulk-fill' );
	var statusEl = document.getElementById( 'patu-bulk-status' );
	var logEl = document.getElementById( 'patu-bulk-log' );
	if ( ! optimizeBtn ) {
		return;
	}

	var stopped = false;

	function post( data ) {
		var body = new URLSearchParams();
		body.set( 'nonce', PatuBulk.nonce );
		Object.keys( data ).forEach( function ( k ) { body.set( k, data[ k ] ); } );
		return fetch( PatuBulk.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function log( cls, text ) {
		var line = document.createElement( 'div' );
		line.className = cls;
		line.textContent = text;
		logEl.appendChild( line );
		logEl.scrollTop = logEl.scrollHeight;
	}

	function human( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var u = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( 1 ) + ' ' + u[ i ];
	}

	function setBusy( busy ) {
		optimizeBtn.disabled = busy;
		restoreBtn.disabled = busy;
		stopBtn.style.display = busy ? '' : 'none';
	}

	function run( op ) {
		stopped = false;
		setBusy( true );
		bar.style.display = '';
		logEl.style.display = '';
		logEl.innerHTML = '';
		fill.style.width = '0';
		statusEl.textContent = PatuBulk.i18n.scanning;

		post( { action: 'patu_bulk_ids', op: op } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				statusEl.textContent = ( res && res.data && res.data.message ) || 'Error';
				setBusy( false );
				return;
			}
			var ids = res.data.ids || [];
			if ( ! ids.length ) {
				statusEl.textContent = PatuBulk.i18n.none;
				setBusy( false );
				bar.style.display = 'none';
				return;
			}

			var total = ids.length;
			var doneCount = 0;
			var savedTotal = 0;

			function step() {
				if ( stopped || ! ids.length ) {
					finish( total, doneCount, savedTotal );
					return;
				}
				var id = ids.shift();
				post( { action: 'patu_bulk_one', op: op, id: id } ).then( function ( r ) {
					doneCount++;
					if ( r && r.success ) {
						var d = r.data;
						if ( op === 'restore' ) {
							log( 'ok', ( d.title || ( '#' + d.id ) ) + ' — restored' );
						} else if ( d.skipped ) {
							log( 'skip', ( d.title || ( '#' + d.id ) ) + ' — skipped' );
						} else if ( d.failed > 0 && d.optimized === 0 ) {
							log( 'err', ( d.title || ( '#' + d.id ) ) + ' — failed' );
						} else {
							savedTotal += ( d.saved || 0 );
							log( 'ok', ( d.title || ( '#' + d.id ) ) + ' — ' + human( d.saved || 0 ) + ' ' + PatuBulk.i18n.saved );
						}
					} else {
						log( 'err', '#' + id + ' — error' );
					}
					fill.style.width = Math.round( ( doneCount / total ) * 100 ) + '%';
					statusEl.textContent = doneCount + ' / ' + total + '  (' + human( savedTotal ) + ' ' + PatuBulk.i18n.saved + ')';
					step();
				} ).catch( function () {
					doneCount++;
					log( 'err', '#' + id + ' — request failed' );
					step();
				} );
			}

			step();
		} );
	}

	function finish( total, doneCount, savedTotal ) {
		setBusy( false );
		statusEl.textContent = PatuBulk.i18n.done + '  ' + doneCount + ' / ' + total + '  (' + human( savedTotal ) + ' ' + PatuBulk.i18n.saved + ')';
	}

	optimizeBtn.addEventListener( 'click', function () { run( 'optimize' ); } );
	restoreBtn.addEventListener( 'click', function () { run( 'restore' ); } );
	stopBtn.addEventListener( 'click', function () { stopped = true; } );
} )();
