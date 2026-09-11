/* EPIC Distributor Profit — admin JS: order search + live profit preview. */
( function ( $ ) {
	'use strict';

	function fillFromOrder( order ) {
		$( '#epic-dp-wc-order-id' ).val( order.id );
		$( '#order_code' ).val( order.number );
		$( '#product_name' ).val( order.product_name );
		$( '#quantity' ).val( order.quantity );
		$( '#revenue' ).val( order.total );
		$( '#shipping_cost' ).val( order.shipping_total );
		recalcPreview();
	}

	function renderResults( orders ) {
		var $results = $( '#epic-dp-order-results' );
		$results.empty();

		if ( ! orders.length ) {
			$results.text( EpicDistributorProfit.i18n.noResults );
			return;
		}

		orders.forEach( function ( order ) {
			var label = '#' + order.number + ' — ' + order.date + ' — ' + order.product_name +
				' — ' + order.total + ' (' + order.status + ')';
			var $btn = $( '<button type="button" class="epic-dp-order-result"></button>' ).text( label );
			$btn.on( 'click', function () {
				fillFromOrder( order );
				$results.empty();
			} );
			$results.append( $btn );
		} );
	}

	function searchOrders() {
		var term = $( '#epic-dp-order-search' ).val();
		if ( ! term ) {
			return;
		}
		var $results = $( '#epic-dp-order-results' );
		$results.text( EpicDistributorProfit.i18n.searching );

		$.get( EpicDistributorProfit.ajaxUrl, {
			action: 'epic_dp_search_orders',
			nonce: EpicDistributorProfit.searchNonce,
			term: term
		} ).done( function ( response ) {
			if ( response && response.success ) {
				renderResults( response.data );
			} else {
				$results.text( EpicDistributorProfit.i18n.searchError );
			}
		} ).fail( function () {
			$results.text( EpicDistributorProfit.i18n.searchError );
		} );
	}

	function recalcPreview() {
		var revenue    = parseFloat( $( '#revenue' ).val() ) || 0;
		var cost       = parseFloat( $( '#cost' ).val() ) || 0;
		var shipping   = parseFloat( $( '#shipping_cost' ).val() ) || 0;
		var otherCost  = parseFloat( $( '#other_cost_amount' ).val() ) || 0;
		var $selected  = $( '#distributor_id_field option:selected' );
		var commission = parseFloat( $selected.data( 'commission' ) ) || 0;

		var gross = revenue - cost - shipping - otherCost;
		var comm  = gross * ( commission / 100 );
		var net   = gross - comm;

		var fmt = function ( n ) {
			return Math.round( n ).toLocaleString();
		};

		$( '#epic-dp-preview-gross' ).text( fmt( gross ) );
		$( '#epic-dp-preview-commission' ).text( fmt( comm ) + ' (' + commission.toFixed( 1 ) + '%)' );
		$( '#epic-dp-preview-net' ).text( fmt( net ) );
	}

	$( function () {
		$( '#epic-dp-order-clear-btn' ).on( 'click', function () {
			$( '#epic-dp-wc-order-id' ).val( '' );
			$( this ).closest( 'p' ).html( '<em>' + ( EpicDistributorProfit.i18n.orderUnlinked || 'Order unlinked — will be saved as a manual entry.' ) + '</em>' );
		} );

		$( '#epic-dp-order-search-btn' ).on( 'click', searchOrders );
		$( '#epic-dp-order-search' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				searchOrders();
			}
		} );

		$( document ).on( 'input change', '.epic-dp-calc, #distributor_id_field', recalcPreview );
		recalcPreview();
	} );
} )( jQuery );
