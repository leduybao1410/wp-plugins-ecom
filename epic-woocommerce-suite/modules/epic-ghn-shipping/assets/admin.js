/* global jQuery, EpicGhnAdmin */
( function ( $ ) {
	'use strict';

	if ( typeof EpicGhnAdmin === 'undefined' ) {
		return;
	}

	function ajax( action, data ) {
		return $.post(
			EpicGhnAdmin.ajaxUrl,
			$.extend( { action: action, nonce: EpicGhnAdmin.nonce }, data )
		);
	}

	function setOptions( $select, items, placeholder ) {
		var currentValue = $select.data( 'selected' ) || $select.val() || '';
		$select.empty();
		$select.append( $( '<option></option>' ).attr( 'value', '' ).text( placeholder ) );
		$.each( items, function ( i, item ) {
			$select.append( $( '<option></option>' ).attr( 'value', item.id ).text( item.name ) );
		} );
		if ( currentValue ) {
			$select.val( String( currentValue ) );
		}
		$select.data( 'selected', '' ); // Only honor the server-provided initial selection once.
	}

	function updateHiddenName( $select, $hidden ) {
		var text = $select.find( 'option:selected' ).text();
		$hidden.val( $select.val() ? text : '' );
	}

	/**
	 * Folds a Vietnamese string down to plain lowercase ASCII for matching —
	 * strips tone/vowel diacritics (NFD decomposition + stripping the
	 * resulting combining marks) and separately maps đ/Đ, which don't
	 * decompose that way. Mirrors Epic_GHN_Legacy_Address::normalize() on
	 * the PHP side (used for the *final* GHN-name matching); this client-side
	 * copy exists so the *suggestion list itself* can be accent-insensitive
	 * too — native <datalist> filters on raw substring match against the
	 * literal option text, so typing "Dien Bien" (no diacritics, how most
	 * staff will actually type) would show nothing for "Phường Điện Biên".
	 * Doesn't replicate the PHP side's administrative-prefix stripping
	 * ("phuong ", "quan ", …) — that's for resolving a single known name,
	 * not for ranking a suggestion list, where showing the full name
	 * including "Phường"/"Quận" is more useful, not less.
	 */
	function normalizeVN( value ) {
		return String( null === value || undefined === value ? '' : value )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.replace( /đ/g, 'd' )
			.replace( /Đ/g, 'D' )
			.toLowerCase()
			.trim();
	}

	/**
	 * Populates a ward combo's in-memory item list: an exact-match map
	 * (normalized name -> {id, name}, used by wireCombo()'s 'input' handler
	 * to accept a fully-typed name without requiring a click) and the raw
	 * items array (used by filterComboItems() to build the live suggestion
	 * list as the user types). Also closes/clears any currently-open
	 * suggestion list, since a fresh item set (new district/province picked)
	 * invalidates whatever was showing.
	 */
	function setComboOptions( $input, $list, items ) {
		var map = {};
		$.each( items, function ( i, item ) {
			map[ normalizeVN( item.name ) ] = item;
		} );
		$input.data( 'items', map );
		$input.data( 'itemsList', items );
		$list.empty().attr( 'hidden', 'hidden' );
		$input.attr( 'aria-expanded', 'false' );
	}

	/**
	 * Filters+ranks a combo's items against whatever's currently typed:
	 * prefix matches first, then "contains" matches, each group sorted by
	 * how early the match falls and then by name length (closer matches
	 * first), capped to a reasonable number of suggestions so a 100+-ward
	 * province doesn't render a giant list on every keystroke.
	 */
	function filterComboItems( items, query ) {
		var q = normalizeVN( query );
		if ( ! q ) {
			return [];
		}

		var scored = [];
		$.each( items, function ( i, item ) {
			var normName = normalizeVN( item.name );
			var index = normName.indexOf( q );
			if ( -1 === index ) {
				return;
			}
			scored.push( { item: item, rank: 0 === index ? 0 : 1, index: index, length: normName.length } );
		} );

		scored.sort( function ( a, b ) {
			if ( a.rank !== b.rank ) {
				return a.rank - b.rank;
			}
			if ( a.index !== b.index ) {
				return a.index - b.index;
			}
			if ( a.length !== b.length ) {
				return a.length - b.length;
			}
			return a.item.name.localeCompare( b.item.name );
		} );

		return $.map( scored.slice( 0, 30 ), function ( s ) {
			return s.item;
		} );
	}

	/**
	 * Renders the filtered items into $list as clickable <li role="option">
	 * rows (text set via .text(), never HTML, since item names ultimately
	 * come from GHN/the bundled admin-boundary data — untrusted enough not
	 * to interpolate into markup) and shows/hides the list accordingly.
	 */
	function renderSuggestions( $input, $list, items ) {
		$list.empty().data( 'active-index', -1 );

		if ( ! items.length ) {
			$list.attr( 'hidden', 'hidden' );
			$input.attr( 'aria-expanded', 'false' );
			return;
		}

		$.each( items, function ( i, item ) {
			$list.append(
				$( '<li class="epic-ghn-combo-option" role="option"></li>' )
					.text( item.name )
					.attr( 'data-id', item.id )
					.attr( 'data-name', item.name )
			);
		} );
		$list.removeAttr( 'hidden' );
		$input.attr( 'aria-expanded', 'true' );
	}

	function selectComboItem( $input, $hidden, $list, item ) {
		$input.val( item.name );
		$hidden.val( item.id );
		$list.empty().attr( 'hidden', 'hidden' );
		$input.attr( 'aria-expanded', 'false' );
	}

	/**
	 * Wires one ward combo's live-search behavior: a suggestion list that
	 * filters and re-sorts on every keystroke (renderSuggestions()), full
	 * keyboard navigation (arrows/Enter/Escape), and click-to-select — plus
	 * $hidden (the real ward code/id field actually submitted) staying in
	 * sync. Deliberately only accepts an exact match into $hidden — typed
	 * exactly (accent-insensitive) or picked from the list; a partial
	 * string, typo, or a name that doesn't exist in this district/province
	 * leaves $hidden blank rather than guessing, so a half-typed search
	 * can't silently submit the wrong ward.
	 */
	function wireCombo( $input, $hidden, $list ) {
		function itemsList() {
			return $input.data( 'itemsList' ) || [];
		}

		function resolveExact() {
			var map = $input.data( 'items' ) || {};
			var match = map[ normalizeVN( $input.val() ) ];
			$hidden.val( match ? match.id : '' );
		}

		$input.on( 'input', function () {
			renderSuggestions( $input, $list, filterComboItems( itemsList(), $input.val() ) );
			resolveExact();
		} );

		$input.on( 'focus', function () {
			if ( $input.val() ) {
				renderSuggestions( $input, $list, filterComboItems( itemsList(), $input.val() ) );
			}
		} );

		// Closing on blur is delayed so a suggestion's own 'mousedown'
		// handler below (which fires before blur) has already run —
		// otherwise the list would disappear before the click registers.
		$input.on( 'blur', function () {
			setTimeout( function () {
				$list.empty().attr( 'hidden', 'hidden' );
				$input.attr( 'aria-expanded', 'false' );
			}, 150 );
		} );

		$input.on( 'keydown', function ( e ) {
			var $options = $list.find( '.epic-ghn-combo-option' );
			if ( ! $options.length ) {
				return;
			}

			var activeIndex = $list.data( 'active-index' );
			if ( 'number' !== typeof activeIndex ) {
				activeIndex = -1;
			}

			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				activeIndex = Math.min( activeIndex + 1, $options.length - 1 );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				activeIndex = Math.max( activeIndex - 1, 0 );
			} else if ( 'Enter' === e.key ) {
				if ( activeIndex > -1 ) {
					e.preventDefault();
					var $chosen = $options.eq( activeIndex );
					selectComboItem( $input, $hidden, $list, { id: $chosen.attr( 'data-id' ), name: $chosen.attr( 'data-name' ) } );
				}
				return;
			} else if ( 'Escape' === e.key ) {
				$list.empty().attr( 'hidden', 'hidden' );
				$input.attr( 'aria-expanded', 'false' );
				return;
			} else {
				return;
			}

			$list.data( 'active-index', activeIndex );
			$options.removeClass( 'is-active' );
			$options.eq( activeIndex ).addClass( 'is-active' );
		} );

		$list.on( 'mousedown', '.epic-ghn-combo-option', function ( e ) {
			e.preventDefault(); // Keep focus on $input so the blur timeout above doesn't race this click.
			var $option = $( this );
			selectComboItem( $input, $hidden, $list, { id: $option.attr( 'data-id' ), name: $option.attr( 'data-name' ) } );
		} );
	}

	/**
	 * The real reason a call failed — GHN's own message, or our server-side
	 * wrapper's ("Token must be set…", etc.) — rather than the one generic
	 * "could not load" string every failure used to collapse into, which
	 * made every actual cause (bad token, wrong environment, missing shop
	 * ID, network error) look identical in the UI.
	 */
	function errorText( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return EpicGhnAdmin.i18n.loadFailed;
	}

	/**
	 * Wires one .epic-ghn-address-group's three cascading selects. Used by
	 * both the Settings screen's pickup-address picker and the order meta
	 * box's manual-override picker — same markup (Epic_GHN_Assets::render_address_group()),
	 * same behavior.
	 */
	function initAddressGroup( $group ) {
		var $province = $group.find( '.epic-ghn-province' );
		var $district = $group.find( '.epic-ghn-district' );
		var $ward = $group.find( '.epic-ghn-ward' );
		var $wardList = $group.find( '.epic-ghn-ward-list' );
		var $wardCode = $group.find( '.epic-ghn-ward-code' );
		var $provinceName = $group.find( '.epic-ghn-province-name' );
		var $districtName = $group.find( '.epic-ghn-district-name' );

		/**
		 * @param {boolean} preserveCurrent Keep whatever's already typed/selected
		 *        (only true for the initial page-load cascade into a
		 *        server-rendered value) instead of clearing it — a real,
		 *        user-driven district change always clears, since the old
		 *        ward no longer belongs to the new district.
		 */
		function loadWards( districtId, preserveCurrent ) {
			$ward.prop( 'disabled', true );
			if ( ! preserveCurrent ) {
				$ward.val( '' );
				$wardCode.val( '' );
				$wardList.empty();
			}

			if ( ! districtId ) {
				$ward.attr( 'placeholder', EpicGhnAdmin.i18n.firstDistrict );
				return;
			}

			$ward.attr( 'placeholder', EpicGhnAdmin.i18n.loading );

			ajax( 'epic_ghn_get_wards', { district_id: districtId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$ward.attr( 'placeholder', errorText( response ) );
					return;
				}
				setComboOptions( $ward, $wardList, response.data.items );
				$ward.prop( 'disabled', false ).attr( 'placeholder', EpicGhnAdmin.i18n.selectWard );
			} );
		}

		function loadDistricts( provinceId, thenSelectDistrictId ) {
			var preserveWard = !! thenSelectDistrictId;

			$district.prop( 'disabled', true ).empty().append(
				$( '<option></option>' ).text( EpicGhnAdmin.i18n.loading )
			);
			$ward.prop( 'disabled', true );
			if ( ! preserveWard ) {
				$ward.val( '' );
				$wardCode.val( '' );
				$wardList.empty();
				$ward.attr( 'placeholder', EpicGhnAdmin.i18n.firstDistrict );
			}

			if ( ! provinceId ) {
				$district.empty().append( $( '<option></option>' ).attr( 'value', '' ).text( EpicGhnAdmin.i18n.firstProvince ) );
				$districtName.val( '' );
				return;
			}

			ajax( 'epic_ghn_get_districts', { province_id: provinceId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$district.empty().append( $( '<option></option>' ).text( errorText( response ) ) );
					return;
				}
				if ( thenSelectDistrictId ) {
					$district.data( 'selected', thenSelectDistrictId );
				}
				setOptions( $district, response.data.items, EpicGhnAdmin.i18n.selectDistrict );
				$district.prop( 'disabled', false );
				updateHiddenName( $district, $districtName );

				if ( $district.val() ) {
					loadWards( $district.val(), preserveWard );
				}
			} );
		}

		// Initial load: provinces, then cascade into whatever was pre-selected
		// server-side (the district select's own data-selected attribute;
		// loadDistricts()/loadWards() thread "preserve, don't clear" through
		// from there so province -> district -> ward reaches the
		// server-rendered value in one pass without a visible flash-empty).
		var initialDistrictId = $district.data( 'selected' );

		ajax( 'epic_ghn_get_provinces', {} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$province.empty().append( $( '<option></option>' ).text( errorText( response ) ) );
				return;
			}
			setOptions( $province, response.data.items, EpicGhnAdmin.i18n.selectProvince );
			updateHiddenName( $province, $provinceName );

			if ( $province.val() ) {
				loadDistricts( $province.val(), initialDistrictId );
			}
		} );

		$province.on( 'change', function () {
			updateHiddenName( $province, $provinceName );
			loadDistricts( $province.val() );
		} );
		$district.on( 'change', function () {
			updateHiddenName( $district, $districtName );
			loadWards( $district.val(), false );
		} );
		wireCombo( $ward, $wardCode, $wardList );
	}

	/**
	 * Wires one .epic-ghn-new-address-group's two cascading selects
	 * (province -> ward; no district tier in the post-2025-merger
	 * structure). Mirrors initAddressGroup() above but one level shallower,
	 * and backed by the epic_ghn_get_new_provinces/epic_ghn_get_new_wards
	 * AJAX actions instead of GHN's own (still pre-merger) master data.
	 */
	function initNewAddressGroup( $group ) {
		var $province = $group.find( '.epic-ghn-new-province' );
		var $ward = $group.find( '.epic-ghn-new-ward' );
		var $wardList = $group.find( '.epic-ghn-new-ward-list' );
		var $wardId = $group.find( '.epic-ghn-new-ward-id' );
		var $provinceName = $group.find( '.epic-ghn-new-province-name' );

		// Unlike the old-format district select, this ward combo has no
		// select-repopulation step to thread a data-selected attribute
		// through — its value just sits in the DOM — so "was there already a
		// server-rendered value" is simply "is it non-empty right now".
		var preserveInitialWard = !! ( $ward.val() && $wardId.val() );

		function loadWards( provinceId, preserveCurrent ) {
			$ward.prop( 'disabled', true );
			if ( ! preserveCurrent ) {
				$ward.val( '' );
				$wardId.val( '' );
				$wardList.empty();
			}

			if ( ! provinceId ) {
				$ward.attr( 'placeholder', EpicGhnAdmin.i18n.firstNewProvince );
				return;
			}

			$ward.attr( 'placeholder', EpicGhnAdmin.i18n.loading );

			ajax( 'epic_ghn_get_new_wards', { province_id: provinceId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$ward.attr( 'placeholder', errorText( response ) );
					return;
				}
				setComboOptions( $ward, $wardList, response.data.items );
				$ward.prop( 'disabled', false ).attr( 'placeholder', EpicGhnAdmin.i18n.selectNewWard );
			} );
		}

		ajax( 'epic_ghn_get_new_provinces', {} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$province.empty().append( $( '<option></option>' ).text( errorText( response ) ) );
				return;
			}
			setOptions( $province, response.data.items, EpicGhnAdmin.i18n.selectNewProvince );
			updateHiddenName( $province, $provinceName );

			if ( $province.val() ) {
				loadWards( $province.val(), preserveInitialWard );
			}
		} );

		$province.on( 'change', function () {
			updateHiddenName( $province, $provinceName );
			loadWards( $province.val(), false );
		} );
		wireCombo( $ward, $wardId, $wardList );
	}

	/**
	 * Fills an old-format .epic-ghn-address-group's three selects with a
	 * resolved province/district/ward — used after
	 * epic_ghn_convert_new_address succeeds. Loads the real district/ward
	 * option lists (rather than injecting a single fake option) so staff can
	 * still change the pick afterward if the conversion guessed wrong.
	 */
	function applyResolvedAddress( $group, data ) {
		var $province = $group.find( '.epic-ghn-province' );
		var $district = $group.find( '.epic-ghn-district' );
		var $ward = $group.find( '.epic-ghn-ward' );
		var $wardList = $group.find( '.epic-ghn-ward-list' );
		var $wardCode = $group.find( '.epic-ghn-ward-code' );
		var $provinceName = $group.find( '.epic-ghn-province-name' );
		var $districtName = $group.find( '.epic-ghn-district-name' );

		$province.empty().append(
			$( '<option></option>' ).attr( 'value', data.provinceId ).text( data.provinceName )
		).val( String( data.provinceId ) );
		updateHiddenName( $province, $provinceName );

		$district.prop( 'disabled', true ).empty().append(
			$( '<option></option>' ).text( EpicGhnAdmin.i18n.loading )
		);
		ajax( 'epic_ghn_get_districts', { province_id: data.provinceId } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$district.empty().append( $( '<option></option>' ).text( errorText( response ) ) );
				return;
			}
			setOptions( $district, response.data.items, EpicGhnAdmin.i18n.selectDistrict );
			$district.prop( 'disabled', false ).val( String( data.districtId ) );
			updateHiddenName( $district, $districtName );

			$ward.prop( 'disabled', true ).attr( 'placeholder', EpicGhnAdmin.i18n.loading );
			ajax( 'epic_ghn_get_wards', { district_id: data.districtId } ).done( function ( wardResponse ) {
				if ( ! wardResponse || ! wardResponse.success ) {
					$ward.attr( 'placeholder', errorText( wardResponse ) );
					return;
				}
				setComboOptions( $ward, $wardList, wardResponse.data.items );
				$ward.prop( 'disabled', false ).attr( 'placeholder', EpicGhnAdmin.i18n.selectWard );
				$ward.val( data.wardName );
				$wardCode.val( data.wardCode );
			} );
		} );
	}

	/**
	 * The "Convert to pre-merger address" button on every
	 * .epic-ghn-new-address-group (Settings screen's pickup address and the
	 * order meta box's manual override alike). Delegated on `document` since
	 * the meta box's copy is injected after an AJAX call, same reasoning as
	 * every other delegated handler in this file. Looks for the sibling
	 * old-format picker inside the nearest shared '.epic-ghn-address-picker-wrap'
	 * so this one handler works in both contexts without knowing which
	 * screen it's on.
	 */
	function initConvertNewAddressButtons() {
		$( document ).on( 'click', '.epic-ghn-convert-new-address', function () {
			var $button = $( this );
			var $newGroup = $button.closest( '.epic-ghn-new-address-group' );
			var $feedback = $newGroup.find( '.epic-ghn-convert-feedback' );
			var $wrap = $button.closest( '.epic-ghn-address-picker-wrap' );
			var $oldGroup = $wrap.length ? $wrap.find( '.epic-ghn-address-group' ) : $( [] );
			var newWardId = $newGroup.find( '.epic-ghn-new-ward-id' ).val();

			if ( ! newWardId ) {
				$feedback.empty().text( EpicGhnAdmin.i18n.firstNewWard );
				return;
			}

			function runConvert( candidateIndex ) {
				$button.prop( 'disabled', true );
				$feedback.empty().text( EpicGhnAdmin.i18n.converting );

				var payload = { new_ward_id: newWardId };
				if ( null !== candidateIndex ) {
					payload.candidate_index = candidateIndex;
				}

				ajax( 'epic_ghn_convert_new_address', payload ).done( function ( response ) {
					$button.prop( 'disabled', false );

					if ( ! response || ! response.success ) {
						$feedback.empty().text( errorText( response ) );
						return;
					}

					if ( response.data.ambiguous ) {
						var $list = $( '<div class="epic-ghn-convert-choices"></div>' );
						$list.append( $( '<p></p>' ).text( EpicGhnAdmin.i18n.convertAmbiguous ) );
						$.each( response.data.choices, function ( i, choice ) {
							$list.append(
								$( '<button type="button" class="button button-small epic-ghn-convert-choice"></button>' )
									.text( choice.label )
									.data( 'index', choice.index )
							);
						} );
						$feedback.empty().append( $list );
						return;
					}

					if ( $oldGroup.length ) {
						applyResolvedAddress( $oldGroup, response.data );
					}
					$feedback.empty().text( EpicGhnAdmin.i18n.convertApplied );
				} ).fail( function () {
					$button.prop( 'disabled', false );
					$feedback.empty().text( EpicGhnAdmin.i18n.genericError );
				} );
			}

			runConvert( null );

			$feedback.off( 'click', '.epic-ghn-convert-choice' ).on( 'click', '.epic-ghn-convert-choice', function () {
				runConvert( $( this ).data( 'index' ) );
			} );
		} );
	}

	/**
	 * Order meta box: Ship / Cancel / Print / Refresh status.
	 */
	function initOrderMetaBox( $box ) {
		var orderId = $box.data( 'order-id' );

		function feedback( message, isError ) {
			var $el = $box.find( '.epic-ghn-feedback' );
			$el.text( message || '' ).toggleClass( 'epic-ghn-feedback-error', !! isError );
		}

		function enableShipButton() {
			$box.find( '.epic-ghn-action[data-action="ship_order"]' ).prop( 'disabled', false );
		}

		function errorNotice( message ) {
			var $notice = $( '<div class="notice notice-error inline epic-ghn-inline-notice"><p></p></div>' );
			$notice.find( 'p' ).text( message );
			return $notice;
		}

		/**
		 * Resolves this order's address against GHN's province/district/ward
		 * data via AJAX — deliberately *not* done server-side while the order
		 * screen itself renders (see the comment in
		 * Epic_GHN_Order_Meta_Box::render_unbooked_state()): a slow or failed
		 * GHN call here now only leaves this one box showing an error, rather
		 * than risking the whole order screen.
		 */
		function resolveAddress() {
			var $container = $box.find( '.epic-ghn-address-resolution' );
			if ( ! $container.length ) {
				return; // Already booked — nothing to resolve.
			}

			ajax( 'epic_ghn_resolve_address', { order_id: orderId } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$container.empty().append( errorNotice( errorText( response ) ) );
					enableShipButton();
					return;
				}

				var data = response.data;

				if ( data.resolved ) {
					var noticeText = EpicGhnAdmin.i18n.addressMatched;
					if ( 'new_converted' === data.source ) {
						noticeText = EpicGhnAdmin.i18n.convertedNotice
							.replace( '%1$s', data.newProvinceName || '' )
							.replace( '%2$s', data.newWardName || '' );
					}
					var $resolved = $( '<p class="epic-ghn-resolved-address"></p>' )
						.append( $( '<span class="dashicons dashicons-yes-alt"></span>' ) )
						.append( document.createTextNode( ' ' + noticeText ) );

					$container.empty().append( $resolved );
					$container.append( $( '<input type="hidden" class="epic-ghn-resolved-province-id">' ).val( data.provinceId ) );
					$container.append( $( '<input type="hidden" class="epic-ghn-resolved-district-id">' ).val( data.districtId ) );
					$container.append( $( '<input type="hidden" class="epic-ghn-resolved-ward-code">' ).val( data.wardCode ) );
				} else {
					$container.empty().addClass( 'epic-ghn-address-picker-wrap' );
					if ( data.error ) {
						$container.append( errorNotice( data.error ) );
					}
					$container.append(
						$( '<div class="notice notice-warning inline epic-ghn-inline-notice"><p></p></div>' )
							.find( 'p' ).text(
								data.hasLegacyCandidates ? EpicGhnAdmin.i18n.legacyHintPrefix : EpicGhnAdmin.i18n.addressNotMatched
							).end()
					);
					// Server-rendered by Epic_GHN_Assets::render_address_group() /
					// render_new_address_group() — escaped there, delivered over
					// our own nonce-verified admin-ajax channel, same trust level
					// as any other server-rendered admin fragment.
					$container.append( data.html );
					initAddressGroup( $container.find( '.epic-ghn-address-group' ) );

					if ( data.newAddressHtml ) {
						var $newToggle = $( '<p><button type="button" class="button-link epic-ghn-toggle-new-address"></button></p>' );
						$newToggle.find( 'button' ).text( EpicGhnAdmin.i18n.newAddressToggle );
						var $newWrap = $( '<div class="epic-ghn-new-address-wrap" style="display:none;"></div>' ).append( data.newAddressHtml );

						$container.append( $newToggle ).append( $newWrap );
						initNewAddressGroup( $newWrap.find( '.epic-ghn-new-address-group' ) );

						$newToggle.find( 'button' ).on( 'click', function () {
							$newWrap.toggle();
						} );
					}
				}

				enableShipButton();
			} ).fail( function () {
				$container.empty().append( errorNotice( EpicGhnAdmin.i18n.genericError ) );
				enableShipButton();
			} );
		}

		resolveAddress();

		$box.on( 'click', '.epic-ghn-action', function () {
			var $button = $( this );
			var action = $button.data( 'action' );
			$box.find( '.epic-ghn-action' ).prop( 'disabled', true );

			if ( 'ship_order' === action ) {
				var payload = { order_id: orderId };
				var $group = $box.find( '.epic-ghn-address-group' );

				if ( $group.length ) {
					payload.district_id = $group.find( '.epic-ghn-district' ).val();
					payload.ward_code = $group.find( '.epic-ghn-ward-code' ).val();
				} else {
					payload.district_id = $box.find( '.epic-ghn-resolved-district-id' ).val();
					payload.ward_code = $box.find( '.epic-ghn-resolved-ward-code' ).val();
				}

				feedback( EpicGhnAdmin.i18n.shipping );
				ajax( 'epic_ghn_ship_order', payload ).done( function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
						return;
					}
					feedback( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError, true );
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
				} ).fail( function () {
					feedback( EpicGhnAdmin.i18n.genericError, true );
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
				} );
			} else if ( 'cancel_shipment' === action ) {
				if ( ! window.confirm( EpicGhnAdmin.i18n.confirmCancel ) ) {
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
					return;
				}
				feedback( EpicGhnAdmin.i18n.cancelling );
				ajax( 'epic_ghn_cancel_shipment', { order_id: orderId } ).done( function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
						return;
					}
					feedback( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError, true );
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
				} ).fail( function () {
					feedback( EpicGhnAdmin.i18n.genericError, true );
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
				} );
			} else if ( 'print_label' === action ) {
				feedback( EpicGhnAdmin.i18n.generatingLabel );
				ajax( 'epic_ghn_print_label', { order_id: orderId } ).done( function ( response ) {
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
					if ( response && response.success ) {
						window.open( response.data.url, '_blank' );
						feedback( '' );
						return;
					}
					feedback( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError, true );
				} ).fail( function () {
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
					feedback( EpicGhnAdmin.i18n.genericError, true );
				} );
			} else if ( 'sync_status' === action ) {
				feedback( EpicGhnAdmin.i18n.syncing );
				ajax( 'epic_ghn_sync_status', { order_id: orderId } ).done( function ( response ) {
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
					if ( response && response.success ) {
						$box.find( '.epic-ghn-status-value' ).text( response.data.status || '' );
						feedback( '' );
						return;
					}
					feedback( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError, true );
				} ).fail( function () {
					$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
					feedback( EpicGhnAdmin.i18n.genericError, true );
				} );
			} else {
				$box.find( '.epic-ghn-action' ).prop( 'disabled', false );
			}
		} );
	}

	/**
	 * Orders list: the "Bundle & ship via GHN" bulk action option is always
	 * present in the dropdown (WordPress builds bulk-action <select>s once,
	 * server-side, with no concept of "currently checked rows"), but PLAN.md
	 * §5.1 wants it to only be usable once 2+ orders are checked. This
	 * disables/enables the option client-side to match; the server-side
	 * handler (Epic_GHN_Orders_List::handle_bulk_action()) still enforces the
	 * same rule on submit regardless, since a <select> can be submitted even
	 * with JS disabled or failed to load.
	 */
	function initOrdersListBulkAction() {
		var $selects = $( 'select[name="action"], select[name="action2"]' );
		var $options = $selects.find( 'option[value="epic_ghn_bundle"]' );
		if ( ! $options.length ) {
			return;
		}

		function refresh() {
			var checked = $( '#the-list input[type="checkbox"]:checked' ).length;
			var enabled = checked >= 2;

			$options.prop( 'disabled', ! enabled );

			if ( ! enabled ) {
				$selects.each( function () {
					if ( 'epic_ghn_bundle' === $( this ).val() ) {
						$( this ).val( '-1' );
					}
				} );
			}
		}

		$( document ).on(
			'change',
			'#the-list input[type="checkbox"], #cb-select-all-1, #cb-select-all-2',
			refresh
		);
		refresh();
	}

	/**
	 * Orders list: the "Create GHN shipment(s)" bulk action books real GHN
	 * shipments the moment the bulk-actions form is submitted — no review
	 * screen like bundling gets, since each order books independently with
	 * its own already-automatic logic (see Epic_GHN_Ajax::book_single_order()).
	 * A window.confirm() naming how many orders are about to be booked is
	 * the only "are you sure" staff get before that fires, matching the
	 * confirmCancel guard on the single-order Cancel shipment button. This
	 * is a courtesy only — WordPress's bulk-actions form has no per-action
	 * hook to attach a *required* confirmation to, so a submit with JS
	 * disabled/failed still goes through unconfirmed.
	 */
	function initOrdersListBulkShipConfirm() {
		// Attach to whichever form(s) actually contain the bulk-action
		// selects rather than guessing an id — WordPress's legacy list
		// table and WooCommerce's HPOS orders screen don't necessarily
		// share one form id, and top/bottom bulk-action bars can each be
		// their own <form> depending on the screen.
		var $selects = $( 'select[name="action"], select[name="action2"]' );
		var $form    = $selects.closest( 'form' );
		if ( ! $form.length ) {
			return;
		}

		$form.on( 'submit', function ( e ) {
			var action = $( 'select[name="action"]' ).val();
			if ( '-1' === action ) {
				action = $( 'select[name="action2"]' ).val();
			}
			if ( 'epic_ghn_bulk_ship' !== action ) {
				return;
			}

			var checked = $( '#the-list input[type="checkbox"]:checked' ).length;
			if ( ! checked ) {
				return;
			}

			var message = EpicGhnAdmin.i18n.confirmBulkShip.replace( '%d', checked );
			if ( ! window.confirm( message ) ) {
				e.preventDefault();
			}
		} );
	}

	/**
	 * Orders list "Action" column — the per-row "Create Shipment" button
	 * (Epic_GHN_Orders_List::render_action_cell()). Delegated on `document`
	 * since the list table's rows are WordPress/WooCommerce markup this
	 * script doesn't control the lifecycle of, same reasoning as every other
	 * delegated handler in this file. Calls the exact same epic_ghn_ship_order
	 * AJAX action as the order meta box's own "Ship via GHN" button — just
	 * with no manual address-override picker (there's no room for one in a
	 * list-table cell), so, like the bulk action, this always auto-resolves
	 * the address. On success the whole page reloads, matching the meta
	 * box's own ship/cancel buttons, so every affected cell (COD, Shipment,
	 * Action) and any still-showing bulk-ship summary notice all end up
	 * consistent rather than hand-patched piecemeal.
	 */
	function initOrdersListRowShip() {
		$( document ).on( 'click', '.epic-ghn-list-ship', function () {
			var $button = $( this );
			var $feedback = $button.siblings( '.epic-ghn-list-ship-feedback' );
			var orderId = $button.data( 'order-id' );

			$button.prop( 'disabled', true );
			$feedback.removeClass( 'epic-ghn-feedback-error' ).text( EpicGhnAdmin.i18n.shipping );

			ajax( 'epic_ghn_ship_order', { order_id: orderId } ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
					return;
				}
				$button.prop( 'disabled', false );
				$feedback.addClass( 'epic-ghn-feedback-error' ).text( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError );
			} ).fail( function () {
				$button.prop( 'disabled', false );
				$feedback.addClass( 'epic-ghn-feedback-error' ).text( EpicGhnAdmin.i18n.genericError );
			} );
		} );
	}

	/**
	 * Orders list "Action" column — the per-row "Print label" button for
	 * already-shipped orders (Epic_GHN_Orders_List::render_action_cell()).
	 * Same epic_ghn_print_label AJAX action as the order meta box's own
	 * "Print label" button (see the 'print_label' branch in
	 * initOrderMetaBox() above); delegated on `document` for the same reason
	 * as initOrdersListRowShip(). Unlike the Ship button, success doesn't
	 * reload the page — printing a label doesn't change any order state the
	 * COD/Shipment/Action cells need to reflect, so this just opens the
	 * label URL in a new tab and re-enables the button.
	 */
	function initOrdersListRowPrint() {
		$( document ).on( 'click', '.epic-ghn-list-print', function () {
			var $button = $( this );
			var $feedback = $button.siblings( '.epic-ghn-list-print-feedback' );
			var orderId = $button.data( 'order-id' );

			$button.prop( 'disabled', true );
			$feedback.removeClass( 'epic-ghn-feedback-error' ).text( EpicGhnAdmin.i18n.generatingLabel );

			ajax( 'epic_ghn_print_label', { order_id: orderId } ).done( function ( response ) {
				$button.prop( 'disabled', false );
				if ( response && response.success ) {
					window.open( response.data.url, '_blank' );
					$feedback.text( '' );
					return;
				}
				$feedback.addClass( 'epic-ghn-feedback-error' ).text( ( response && response.data && response.data.message ) || EpicGhnAdmin.i18n.genericError );
			} ).fail( function () {
				$button.prop( 'disabled', false );
				$feedback.addClass( 'epic-ghn-feedback-error' ).text( EpicGhnAdmin.i18n.genericError );
			} );
		} );
	}

	/**
	 * Bundle review screen: the Confirm button stays disabled while a
	 * recipient mismatch exists and the override box isn't checked, so the
	 * "logged manual override" (PLAN.md §5.2) is a deliberate click, not a
	 * default. The server-side confirm handler enforces this too — this is
	 * just a courtesy, not the actual guard.
	 */
	function initBundleReviewForm() {
		var $form = $( '.epic-ghn-bundle-form' );
		if ( ! $form.length ) {
			return;
		}

		var hasMismatch = !! $form.data( 'has-mismatch' );
		var $override = $form.find( '#epic-ghn-bundle-override' );
		var $confirm = $form.find( '.epic-ghn-bundle-confirm' );

		function refresh() {
			var blocked = hasMismatch && ! ( $override.length && $override.is( ':checked' ) );
			$confirm.prop( 'disabled', blocked );
		}

		$override.on( 'change', refresh );
		refresh();
	}

	$( function () {
		$( '.epic-ghn-address-group' ).each( function () {
			initAddressGroup( $( this ) );
		} );
		$( '.epic-ghn-metabox' ).each( function () {
			initOrderMetaBox( $( this ) );
		} );
		$( '.epic-ghn-new-address-group' ).each( function () {
			initNewAddressGroup( $( this ) );
		} );
		initConvertNewAddressButtons();
		initOrdersListBulkAction();
		initOrdersListBulkShipConfirm();
		initOrdersListRowShip();
		initOrdersListRowPrint();
		initBundleReviewForm();
	} );
} )( jQuery );
