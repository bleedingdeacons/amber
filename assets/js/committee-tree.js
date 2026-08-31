/**
 * Committee tree drag-and-drop.
 *
 * Native HTML5 drag events, no jQuery UI and no library: the whole interaction
 * is "pick up a member chip, drop it on a committee heading", which the browser
 * already implements.
 *
 * Move is the default and copy is Ctrl/Cmd, matching how file managers behave.
 * The modifier is read on drop rather than on dragstart, because a user decides
 * to copy after they have started dragging at least as often as before.
 *
 * Everything drag does, the per-member <select> also does. That is not a
 * nicety: dragging is unusable without a pointer, and the select is the whole
 * keyboard and screen-reader path.
 */
( function () {
	'use strict';

	var config = window.amberCommitteeTree || {};
	var tree = document.querySelector( '.amber-committee-tree' );

	if ( ! tree || ! config.ajaxUrl ) {
		return;
	}

	var dragging = null;

	/**
	 * Send one assignment change and reload on success.
	 *
	 * The page is re-rendered rather than patched in the DOM. Moving one member
	 * changes the counts on both committees, can empty a list, and with copy can
	 * put the same person in two places -- reproducing all of that client-side
	 * means a second implementation of the render that can disagree with the
	 * first. A reload is a few hundred milliseconds and is always right.
	 */
	function assign( memberId, sourceId, targetId, mode ) {
		var body = new URLSearchParams();
		body.set( 'action', config.action );
		body.set( 'nonce', config.nonce );
		body.set( 'member', String( memberId ) );
		body.set( 'source', String( sourceId ) );
		body.set( 'target', String( targetId ) );
		body.set( 'mode', mode );

		tree.classList.add( 'amber-busy' );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: 'The server sent an unreadable reply.' } };
				} );
			} )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					window.location.reload();
					return;
				}

				tree.classList.remove( 'amber-busy' );
				window.alert(
					( payload && payload.data && payload.data.message ) ||
					'That change could not be saved.'
				);
			} )
			.catch( function () {
				tree.classList.remove( 'amber-busy' );
				window.alert( 'That change could not be saved.' );
			} );
	}

	// --- Dragging -----------------------------------------------------------

	tree.addEventListener( 'dragstart', function ( event ) {
		var member = event.target.closest( '.amber-member' );

		if ( ! member ) {
			return;
		}

		dragging = {
			member: parseInt( member.dataset.member, 10 ),
			source: parseInt( member.dataset.source, 10 )
		};

		member.classList.add( 'amber-dragging' );

		// Firefox will not start a drag unless something is written to the
		// dataTransfer, even when the payload is tracked in a variable.
		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'copyMove';
			event.dataTransfer.setData( 'text/plain', String( dragging.member ) );
		}
	} );

	tree.addEventListener( 'dragend', function () {
		var active = tree.querySelector( '.amber-dragging' );

		if ( active ) {
			active.classList.remove( 'amber-dragging' );
		}

		dragging = null;
		clearDropTarget();
	} );

	function clearDropTarget() {
		var marked = tree.querySelectorAll( '.amber-drop-target' );

		Array.prototype.forEach.call( marked, function ( node ) {
			node.classList.remove( 'amber-drop-target' );
		} );
	}

	tree.addEventListener( 'dragover', function ( event ) {
		var head = event.target.closest( '.amber-committee-head' );

		if ( ! head || ! dragging ) {
			return;
		}

		// Dropping a member on the committee they are already in is a no-op,
		// so it is not offered as a target at all.
		if ( parseInt( head.dataset.committee, 10 ) === dragging.source ) {
			return;
		}

		event.preventDefault();

		if ( event.dataTransfer ) {
			event.dataTransfer.dropEffect = ( event.ctrlKey || event.metaKey ) ? 'copy' : 'move';
		}

		if ( ! head.classList.contains( 'amber-drop-target' ) ) {
			clearDropTarget();
			head.classList.add( 'amber-drop-target' );
		}
	} );

	tree.addEventListener( 'dragleave', function ( event ) {
		var head = event.target.closest( '.amber-committee-head' );

		if ( head ) {
			head.classList.remove( 'amber-drop-target' );
		}
	} );

	tree.addEventListener( 'drop', function ( event ) {
		var head = event.target.closest( '.amber-committee-head' );

		if ( ! head || ! dragging ) {
			return;
		}

		event.preventDefault();

		var target = parseInt( head.dataset.committee, 10 );

		if ( target === dragging.source ) {
			return;
		}

		// Unassigned is a valid destination for a move and a meaningless one
		// for a copy; the server refuses the latter, and this avoids the round
		// trip to be told so.
		var mode = ( event.ctrlKey || event.metaKey ) ? 'copy' : 'move';

		if ( target === 0 && mode === 'copy' ) {
			return;
		}

		assign( dragging.member, dragging.source, target, mode );
	} );

	// --- Keyboard and pointer-free path -------------------------------------

	tree.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '.amber-member-move' );

		if ( ! select || ! select.value ) {
			return;
		}

		var parts = select.value.split( ':' );

		assign(
			parseInt( select.dataset.member, 10 ),
			parseInt( select.dataset.source, 10 ),
			parseInt( parts[ 1 ], 10 ),
			parts[ 0 ] === 'copy' ? 'copy' : 'move'
		);
	} );
}() );
