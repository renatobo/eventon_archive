/**
 * Count-up animation for the archive totals.
 *
 * The final numbers are already in the DOM, server-rendered. This script only
 * winds them back and runs them forward again, so crawlers, no-JS readers and
 * anyone who prefers reduced motion always see the true figure. If this file
 * fails to load, nothing is lost.
 */
( function () {
	'use strict';

	var nodes = document.querySelectorAll( '[data-eventon-count]' );

	if ( ! nodes.length ) {
		return;
	}

	// Respect the OS setting: leave the server-rendered numbers alone.
	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	var DURATION = 1100;

	function format( value ) {
		try {
			return value.toLocaleString();
		} catch ( e ) {
			return String( value );
		}
	}

	function run( node ) {
		var target = parseInt( node.getAttribute( 'data-eventon-count' ), 10 );

		if ( ! target || target < 2 ) {
			return;
		}

		// Start part way up rather than from zero. Counting 376 from 0 reads as a
		// loading spinner; starting near a fifth reads as a tally settling.
		var from = Math.floor( target * 0.15 );
		var started = null;

		// Lock the width to the final value first, so the surrounding layout does
		// not reflow on every frame as digits are added.
		node.style.minWidth = node.offsetWidth + 'px';
		node.textContent = format( from );

		function frame( now ) {
			if ( started === null ) {
				started = now;
			}

			var progress = Math.min( ( now - started ) / DURATION, 1 );
			// easeOutCubic: quick off the line, gentle arrival.
			var eased = 1 - Math.pow( 1 - progress, 3 );

			node.textContent = format( Math.round( from + ( target - from ) * eased ) );

			if ( progress < 1 ) {
				window.requestAnimationFrame( frame );
			} else {
				node.textContent = format( target );
			}
		}

		window.requestAnimationFrame( frame );
	}

	// Only animate once the strip is actually on screen, and only once.
	if ( 'IntersectionObserver' in window ) {
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						observer.unobserve( entry.target );
						run( entry.target );
					}
				} );
			},
			{ threshold: 0.4 }
		);

		Array.prototype.forEach.call( nodes, function ( node ) {
			observer.observe( node );
		} );

		return;
	}

	Array.prototype.forEach.call( nodes, run );
} )();
