/**
 * Committee tree: pane selection and drag-and-drop.
 *
 * Two panes. The left is the committee hierarchy, the right is the members of
 * whichever committee is selected. Every panel is already in the document, so
 * selecting is a visibility swap rather than a fetch.
 *
 * Dragging uses native HTML5 drag events, no jQuery UI and no library: the
 * whole interaction is "pick up a member row, drop it on a committee", which
 * the browser already implements.
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
	var layout = document.querySelector( '.amber-committee-layout' );

	if ( ! layout || ! config.ajaxUrl ) {
		return;
	}

	var rows = layout.querySelectorAll( '.amber-tree-row' );
	var panels = layout.querySelectorAll( '.amber-member-panel' );
	var dragging = null;

	// --- Selection ----------------------------------------------------------

	function select( committeeId ) {
		var found = false;

		Array.prototype.forEach.call( panels, function ( panel ) {
			var match = panel.dataset.committee === String( committeeId );
			panel.hidden = ! match;
			found = found || match;
		} );

		if ( ! found ) {
			return false;
		}

		Array.prototype.forEach.call( rows, function ( row ) {
			row.setAttribute(
				'aria-selected',
				row.dataset.committee === String( committeeId ) ? 'true' : 'false'
			);
		} );

		// Recorded in the fragment so the selection survives the reload after a
		// move. Without it every drag would bounce the user back to the first
		// root, which is exactly where they are not working.
		window.history.replaceState( null, '', '#committee-' + committeeId );

		return true;
	}

	layout.addEventListener( 'click', function ( event ) {
		var row = event.target.closest( '.amber-tree-row' );

		if ( row ) {
			select( row.dataset.committee );
		}
	} );

	layout.addEventListener( 'keydown', function ( event ) {
		var row = event.target.closest( '.amber-tree-row' );

		if ( ! row ) {
			return;
		}

		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			select( row.dataset.committee );
			return;
		}

		// Roving through the tree with the arrow keys, over the rows in
		// document order -- which is the order they are drawn in.
		if ( event.key !== 'ArrowDown' && event.key !== 'ArrowUp' ) {
			return;
		}

		event.preventDefault();

		var all = Array.prototype.slice.call( rows );
		var next = all.indexOf( row ) + ( event.key === 'ArrowDown' ? 1 : -1 );

		if ( all[ next ] ) {
			all[ next ].focus();
		}
	} );

	// Restore the committee that was open before the last reload.
	if ( window.location.hash.indexOf( '#committee-' ) === 0 ) {
		select( window.location.hash.replace( '#committee-', '' ) );
	}

	// --- Saving -------------------------------------------------------------

	/**
	 * Send one assignment change and reload on success.
	 *
	 * The page is re-rendered rather than patched in the DOM. Moving one member
	 * changes the counts on both committees, can empty a panel, and with copy
	 * can put the same person in two -- reproducing all of that client-side
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

		layout.classList.add( 'amber-busy' );

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

				layout.classList.remove( 'amber-busy' );
				window.alert(
					( payload && payload.data && payload.data.message ) ||
					'That change could not be saved.'
				);
			} )
			.catch( function () {
				layout.classList.remove( 'amber-busy' );
				window.alert( 'That change could not be saved.' );
			} );
	}

	// --- Dragging -----------------------------------------------------------

	layout.addEventListener( 'dragstart', function ( event ) {
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

	layout.addEventListener( 'dragend', function () {
		var active = layout.querySelector( '.amber-dragging' );

		if ( active ) {
			active.classList.remove( 'amber-dragging' );
		}

		dragging = null;
		clearDropTarget();
	} );

	function clearDropTarget() {
		var marked = layout.querySelectorAll( '.amber-drop-target' );

		Array.prototype.forEach.call( marked, function ( node ) {
			node.classList.remove( 'amber-drop-target' );
		} );
	}

	layout.addEventListener( 'dragover', function ( event ) {
		var row = event.target.closest( '.amber-tree-row' );

		if ( ! row || ! dragging ) {
			return;
		}

		// Dropping a member on the committee they are already in is a no-op,
		// so it is not offered as a target at all.
		if ( parseInt( row.dataset.committee, 10 ) === dragging.source ) {
			return;
		}

		event.preventDefault();

		if ( event.dataTransfer ) {
			event.dataTransfer.dropEffect = ( event.ctrlKey || event.metaKey ) ? 'copy' : 'move';
		}

		if ( ! row.classList.contains( 'amber-drop-target' ) ) {
			clearDropTarget();
			row.classList.add( 'amber-drop-target' );
		}
	} );

	layout.addEventListener( 'dragleave', function ( event ) {
		var row = event.target.closest( '.amber-tree-row' );

		if ( row ) {
			row.classList.remove( 'amber-drop-target' );
		}
	} );

	layout.addEventListener( 'drop', function ( event ) {
		var row = event.target.closest( '.amber-tree-row' );

		if ( ! row || ! dragging ) {
			return;
		}

		event.preventDefault();

		var target = parseInt( row.dataset.committee, 10 );

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

	layout.addEventListener( 'change', function ( event ) {
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
