/* global jQuery, EpicVtpAdmin */
( function ( $ ) {
	'use strict';

	var i18n = ( window.EpicVtpAdmin && EpicVtpAdmin.i18n ) || {};

	function ajax( action, data ) {
		return $.post(
			EpicVtpAdmin.ajaxUrl,
			$.extend( { action: action, nonce: EpicVtpAdmin.nonce }, data || {} )
		);
	}

	function feedback( $el, message, isError ) {
		$el.text( message || '' )
			.removeClass( 'epic-vtp-error epic-vtp-ok' )
			.addClass( isError ? 'epic-vtp-error' : 'epic-vtp-ok' );
	}

	function fold( value ) {
		return ( value || '' )
			.toString()
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.replace( /đ/g, 'd' )
			.replace( /[^a-z0-9\s]/g, ' ' )
			.replace( /\b(thanh pho|tinh|tp|quan|huyen|phuong|xa|thi xa|thi tran)\b/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	// ------------------------------------------------------------------
	// Province / ward picker (settings screen + order meta box override)
	// ------------------------------------------------------------------

	var Address = {
		init: function () {
			$( '.epic-vtp-address-group' ).each( function () {
				Address.initGroup( $( this ) );
			} );
		},

		initGroup: function ( $group ) {
			var $province = $group.find( '.epic-vtp-province' );
			var $ward = $group.find( '.epic-vtp-ward' );
			var $wardId = $group.find( '.epic-vtp-ward-id' );
			var $provinceName = $group.find( '.epic-vtp-province-name' );
			var wards = [];

			function renderWards( items ) {
				wards = items || [];
				$ward.prop( 'disabled', false ).attr( 'placeholder', i18n.typeToSearchWards || 'Type to search wards…' );
			}

			function loadWards( provinceId, preserve ) {
				if ( ! provinceId ) {
					return;
				}
				// Only clear the ward on a user-initiated province change. On
				// page load the province is pre-selected, and clearing here
				// would wipe an already-saved ward (so the next save stored an
				// empty ward).
				if ( ! preserve ) {
					$ward.val( '' );
					$wardId.val( '' );
				}
				$ward.prop( 'disabled', true );
				$ward.attr( 'placeholder', i18n.loading || 'Loading…' );
				ajax( 'epic_vtp_get_wards', { province_id: provinceId } ).done( function ( res ) {
					if ( res && res.success ) {
						renderWards( res.data.items );
					} else {
						$ward.attr( 'placeholder', i18n.loadFailed || '' );
					}
				} ).fail( function () {
					$ward.attr( 'placeholder', i18n.loadFailed || '' );
				} );
			}

			function populateProvinces( items ) {
				var selected = $province.data( 'selected' ) ? String( $province.data( 'selected' ) ) : '';
				$province.empty().append( $( '<option>' ).val( '' ).text( i18n.selectProvince || 'Select…' ) );
				( items || [] ).forEach( function ( item ) {
					$province.append( $( '<option>' ).val( item.id ).text( item.name ) );
				} );
				if ( selected ) {
					$province.val( selected );
				}
			}

			ajax( 'epic_vtp_get_provinces' ).done( function ( res ) {
				if ( res && res.success ) {
					populateProvinces( res.data.items );
					if ( $province.val() ) {
						loadWards( $province.val(), true );
					}
				} else {
					$province.empty().append( $( '<option>' ).val( '' ).text( i18n.loadFailed || 'Error' ) );
				}
			} ).fail( function () {
				$province.empty().append( $( '<option>' ).val( '' ).text( i18n.loadFailed || 'Error' ) );
			} );

			$province.on( 'change', function () {
				$provinceName.val( $province.find( 'option:selected' ).text() );
				loadWards( $province.val() );
			} );

			// Ward free-text combo with accent-insensitive suggestions.
			var $list = $group.find( '.epic-vtp-ward-list' );

			function renderSuggestions( query ) {
				var folded = fold( query );
				if ( ! folded ) {
					$list.attr( 'hidden', 'hidden' ).empty();
					return;
				}
				var matches = wards.filter( function ( w ) {
					return fold( w.name ).indexOf( folded ) !== -1;
				} ).slice( 0, 50 );

				$list.empty();
				if ( ! matches.length ) {
					$list.attr( 'hidden', 'hidden' );
					return;
				}
				matches.forEach( function ( w ) {
					$( '<li>' ).attr( 'role', 'option' ).text( w.name ).data( 'item', w ).appendTo( $list );
				} );
				$list.removeAttr( 'hidden' );
			}

			$ward.on( 'input', function () {
				$wardId.val( '' );
				renderSuggestions( $ward.val() );
			} ).on( 'focus', function () {
				if ( $ward.val() ) {
					renderSuggestions( $ward.val() );
				}
			} );

			$list.on( 'click', 'li', function () {
				var item = $( this ).data( 'item' );
				$ward.val( item.name );
				$wardId.val( item.id );
				$list.attr( 'hidden', 'hidden' ).empty();
			} );

			$( document ).on( 'click', function ( e ) {
				if ( ! $group.find( '.epic-vtp-combo-wrap' ).is( e.target ) && ! $group.find( '.epic-vtp-combo-wrap' ).has( e.target ).length ) {
					$list.attr( 'hidden', 'hidden' );
				}
			} );

			$ward.on( 'blur', function () {
				// Only keep an exact match; a half-typed search must not submit
				// the wrong ward silently.
				var typed = fold( $ward.val() );
				var exact = wards.filter( function ( w ) {
					return fold( w.name ) === typed;
				} )[ 0 ];
				$wardId.val( exact ? exact.id : '' );
			} );
		},
	};

	// ------------------------------------------------------------------
	// Order meta box
	// ------------------------------------------------------------------

	var MetaBox = {
		init: function () {
			$( '.epic-vtp-metabox' ).each( function () {
				var $box = $( this );
				var orderId = $box.data( 'order-id' );

				MetaBox.wireActions( $box, orderId );

				var $resolution = $box.find( '.epic-vtp-address-resolution' );
				if ( ! $resolution.length ) {
					return;
				}
				MetaBox.resolveAddress( $box, orderId, $resolution );
			} );
		},

		resolveAddress: function ( $box, orderId, $resolution ) {
			ajax( 'epic_vtp_resolve_address', { order_id: orderId } ).done( function ( res ) {
				if ( res && res.success && res.data.resolved ) {
					$resolution.html(
						'<p class="epic-vtp-resolved"><span class="epic-vtp-ok">' +
							( i18n.addressMatched || 'Address matched.' ) +
							'</span> <strong>' +
							$( '<div>' ).text( res.data.provinceName ).html() +
							'</strong></p>'
					);
					$resolution.data( 'provinceId', res.data.provinceId );
					$resolution.data( 'provinceName', res.data.provinceName );
					$box.find( '.epic-vtp-action[data-action="ship_order"]' ).prop( 'disabled', false );
				} else {
					$resolution.html(
						'<p class="epic-vtp-not-resolved">' +
							$( '<div>' ).text( ( res && res.data && res.data.error ) || i18n.addressNotMatched || '' ).html() +
							'</p>' + ( ( res && res.data && res.data.html ) || '' )
					);
					Address.initGroup( $resolution.find( '.epic-vtp-address-group' ) );
					$box.find( '.epic-vtp-action[data-action="ship_order"]' ).prop( 'disabled', false );
				}
			} ).fail( function () {
				$resolution.html( '<p class="epic-vtp-not-resolved">' + ( i18n.loadFailed || '' ) + '</p>' );
			} );
		},

		wireActions: function ( $box, orderId ) {
			$box.on( 'click', '.epic-vtp-action', function () {
				var $btn = $( this );
				var action = $btn.data( 'action' );
				var $fb = $box.find( '.epic-vtp-feedback' );

				if ( action === 'ship_order' ) {
					var $res = $box.find( '.epic-vtp-address-resolution' );
					var data = { order_id: orderId };
					if ( $res.data( 'provinceId' ) ) {
						data.province_id = $res.data( 'provinceId' );
						data.province_name = $res.data( 'provinceName' );
					} else {
						var $group = $res.find( '.epic-vtp-address-group' );
						data.province_id = $group.find( '.epic-vtp-province' ).val();
						data.province_name = $group.find( '.epic-vtp-province-name' ).val();
					}
					if ( ! data.province_id ) {
						feedback( $fb, i18n.addressNotMatched || 'Pick a province first.', true );
						return;
					}
					$btn.prop( 'disabled', true );
					feedback( $fb, i18n.shipping || 'Booking…', false );
					ajax( 'epic_vtp_ship_order', data ).done( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
							$btn.prop( 'disabled', false );
						}
					} ).fail( function () {
						feedback( $fb, i18n.genericError, true );
						$btn.prop( 'disabled', false );
					} );
				} else if ( action === 'cancel_shipment' ) {
					if ( ! window.confirm( i18n.confirmCancel || 'Cancel this shipment?' ) ) {
						return;
					}
					$btn.prop( 'disabled', true );
					feedback( $fb, i18n.cancelling || 'Cancelling…', false );
					ajax( 'epic_vtp_cancel_shipment', { order_id: orderId } ).done( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
							$btn.prop( 'disabled', false );
						}
					} ).fail( function () {
						feedback( $fb, i18n.genericError, true );
						$btn.prop( 'disabled', false );
					} );
				} else if ( action === 'print_label' ) {
					$btn.prop( 'disabled', true );
					feedback( $fb, i18n.generatingLabel || 'Generating…', false );
					ajax( 'epic_vtp_print_label', { order_id: orderId } ).done( function ( res ) {
						if ( res && res.success && res.data.url ) {
							window.open( res.data.url, '_blank' );
							feedback( $fb, '', false );
						} else {
							feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
						}
						$btn.prop( 'disabled', false );
					} ).fail( function () {
						feedback( $fb, i18n.genericError, true );
						$btn.prop( 'disabled', false );
					} );
				} else if ( action === 'set_status' ) {
					var status = $box.find( '.epic-vtp-status-select' ).val();
					if ( ! status ) {
						return;
					}
					$btn.prop( 'disabled', true );
					feedback( $fb, i18n.syncing || 'Updating…', false );
					ajax( 'epic_vtp_set_shipment_status', { order_id: orderId, status: status } ).done( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
							$btn.prop( 'disabled', false );
						}
					} ).fail( function () {
						feedback( $fb, i18n.genericError, true );
						$btn.prop( 'disabled', false );
					} );
				}
			} );
		},
	};

	// ------------------------------------------------------------------
	// Orders list row buttons
	// ------------------------------------------------------------------

	var OrdersList = {
		init: function () {
			$( document ).on( 'click', '.epic-vtp-list-ship', function () {
				var $btn = $( this );
				var orderId = $btn.data( 'order-id' );
				var $fb = $btn.siblings( '.epic-vtp-list-ship-feedback' );
				$btn.prop( 'disabled', true );
				feedback( $fb, i18n.shipping || 'Booking…', false );
				ajax( 'epic_vtp_ship_order', { order_id: orderId } ).done( function ( res ) {
					if ( res && res.success ) {
						feedback( $fb, '✓', false );
						window.location.reload();
					} else {
						feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					feedback( $fb, i18n.genericError, true );
					$btn.prop( 'disabled', false );
				} );
			} );

			$( document ).on( 'click', '.epic-vtp-list-print', function () {
				var $btn = $( this );
				var orderId = $btn.data( 'order-id' );
				var $fb = $btn.siblings( '.epic-vtp-list-print-feedback' );
				$btn.prop( 'disabled', true );
				feedback( $fb, i18n.generatingLabel || 'Generating…', false );
				ajax( 'epic_vtp_print_label', { order_id: orderId } ).done( function ( res ) {
					if ( res && res.success && res.data.url ) {
						window.open( res.data.url, '_blank' );
						feedback( $fb, '', false );
					} else {
						feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
					}
					$btn.prop( 'disabled', false );
				} ).fail( function () {
					feedback( $fb, i18n.genericError, true );
					$btn.prop( 'disabled', false );
				} );
			} );
		},
	};

	// ------------------------------------------------------------------
	// Shipments dashboard
	// ------------------------------------------------------------------

	var Dashboard = {
		init: function () {
			var $table = $( '.epic-vtp-shipments' );
			if ( ! $table.length ) {
				return;
			}

			var $all = $table.find( '.epic-vtp-check-all' );
			var $rows = $table.find( '.epic-vtp-row' );
			var $print = $( '.epic-vtp-bulk-print' );
			var $fb = $print.siblings( '.epic-vtp-feedback' );

			function refresh() {
				$print.prop( 'disabled', $rows.filter( ':checked' ).length === 0 );
			}

			$all.on( 'change', function () {
				$rows.prop( 'checked', $all.prop( 'checked' ) );
				refresh();
			} );
			$rows.on( 'change', refresh );

			$print.on( 'click', function () {
				var ids = $rows.filter( ':checked' ).map( function () { return this.value; } ).get();
				if ( ! ids.length ) {
					return;
				}
				$print.prop( 'disabled', true );
				feedback( $fb, i18n.generatingLabel || 'Generating…', false );
				ajax( 'epic_vtp_bulk_print', { order_ids: ids } ).done( function ( res ) {
					if ( res && res.success && res.data.url ) {
						window.open( res.data.url, '_blank' );
						feedback( $fb, '', false );
					} else {
						feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
					}
					refresh();
				} ).fail( function () {
					feedback( $fb, i18n.genericError, true );
					refresh();
				} );
			} );
		},
	};

	$( function () {
		Address.init();
		MetaBox.init();
		OrdersList.init();
		Dashboard.init();

		$( document ).on( 'click', '.epic-vtp-test-connection', function () {
			var $btn = $( this );
			var $fb = $btn.siblings( '.epic-vtp-test-result' );
			$btn.prop( 'disabled', true );
			feedback( $fb, i18n.loading || 'Testing…', false );
			ajax( 'epic_vtp_test_connection' ).done( function ( res ) {
				if ( res && res.success ) {
					feedback( $fb, ( res.data && res.data.message ) || 'OK', false );
				} else {
					feedback( $fb, ( res && res.data && res.data.message ) || i18n.genericError, true );
				}
				$btn.prop( 'disabled', false );
			} ).fail( function () {
				feedback( $fb, i18n.genericError, true );
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
