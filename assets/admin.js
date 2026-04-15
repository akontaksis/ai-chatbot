/**
 * Capitano AI Chatbot — Admin JS
 * Handles: provider card highlighting, key test, key delete.
 */
( function ( $ ) {
    'use strict';

    const nonce = cacbAdmin.nonce;
    const i18n  = cacbAdmin.i18n;

    // ── Provider card highlight ───────────────────────────────────────────────
    function highlightProvider( val ) {
        $( '.cacb-card--provider' ).each( function () {
            $( this ).toggleClass( 'cacb-card--active', $( this ).data( 'provider' ) === val );
        } );
    }
    var $sel = $( '#cacb_provider' );
    if ( $sel.length ) {
        highlightProvider( $sel.val() );
        $sel.on( 'change', function () { highlightProvider( $( this ).val() ); } );
    }

    // ── Test API key ──────────────────────────────────────────────────────────
    $( '.cacb-test-key' ).on( 'click', function () {
        const provider = $( this ).data( 'provider' );
        const $btn     = $( this );
        const $status  = $( '#cacb-test-status-' + provider );

        $btn.prop( 'disabled', true ).text( i18n.testing );
        $status.text( '' ).removeClass( 'cacb-key-ok cacb-key-err' );

        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_test_key', nonce, provider }, function ( res ) {
            $btn.prop( 'disabled', false ).text( i18n.testBtn );
            if ( res.success ) {
                $status.text( '✅ ' + res.data ).addClass( 'cacb-key-ok' );
            } else {
                $status.text( '❌ ' + res.data ).addClass( 'cacb-key-err' );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( i18n.testBtn );
            $status.text( '❌ Network error' ).addClass( 'cacb-key-err' );
        } );
    } );

    // ── Delete API key ────────────────────────────────────────────────────────
    $( '.cacb-delete-key' ).on( 'click', function () {
        if ( ! window.confirm( i18n.confirmDelete ) ) return;

        const option = $( this ).data( 'option' );
        const $btn   = $( this );
        $btn.prop( 'disabled', true );

        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_delete_key', nonce, option }, function ( res ) {
            if ( res.success ) {
                location.reload();
            } else {
                $btn.prop( 'disabled', false );
                alert( res.data || 'Error' );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false );
            alert( 'Network error' );
        } );
    } );

} )( jQuery );
