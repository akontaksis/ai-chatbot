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

    // ── RAG: index status ─────────────────────────────────────────────────────
    var $ragStatus = $( '#cacb-rag-status-wrap' );
    if ( $ragStatus.length ) {
        loadRagStatus();
    }

    function loadRagStatus() {
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_status', nonce: nonce }, function ( res ) {
            if ( ! res.success ) return;
            var d    = res.data;
            var html = '';

            if ( d.provider === 'none' ) {
                html = '<div class="cacb-notice cacb-notice--warn">⚠️ Δεν υπάρχει embedding API key. '
                    + 'Βεβαιώσου ότι το API key του ενεργού provider είναι αποθηκευμένο (ή πρόσθεσε OpenAI key αν χρησιμοποιείς Claude).</div>';
            } else {
                html += '<table style="border-collapse:collapse;font-size:13px">'
                    + '<tr><td style="padding:4px 16px 4px 0"><strong>Provider Embeddings</strong></td>'
                    + '<td>' + esc( d.provider ) + '</td></tr>';

                if ( d.total_products > 0 ) {
                    html += '<tr><td style="padding:4px 16px 4px 0"><strong>Προϊόντα indexed</strong></td>'
                        + '<td>' + d.indexed_products + ' / ' + d.total_products + '</td></tr>';
                }
                if ( d.total_pages > 0 ) {
                    html += '<tr><td style="padding:4px 16px 4px 0"><strong>Σελίδες indexed</strong></td>'
                        + '<td>' + d.indexed_pages + ' / ' + d.total_pages + '</td></tr>';
                }
                if ( d.last_indexed ) {
                    html += '<tr><td style="padding:4px 16px 4px 0"><strong>Τελευταία ευρετηρίαση</strong></td>'
                        + '<td>πριν ' + esc( d.last_indexed ) + '</td></tr>';
                } else {
                    html += '<tr><td colspan="2" style="padding:4px 0">'
                        + '<span style="color:#d63638">⚠ Κανένα περιεχόμενο στον index ακόμα. Πάτα "Index Προϊόντων" παρακάτω.</span>'
                        + '</td></tr>';
                }
                html += '</table>';
            }

            $ragStatus.html( html );
        } );
    }

    function esc( str ) {
        return $( '<div>' ).text( String( str ) ).html();
    }

    // ── RAG: batch index helper ───────────────────────────────────────────────
    function runBatchIndex( type, offset ) {
        var $bar      = $( '#cacb-rag-progress-bar' );
        var $text     = $( '#cacb-rag-progress-text' );
        var $wrap     = $( '#cacb-rag-progress-wrap' );
        var $btnP     = $( '#cacb-rag-index-products' );
        var $btnPg    = $( '#cacb-rag-index-pages' );
        var $btnClear = $( '#cacb-rag-clear' );

        $wrap.show();
        $btnP.prop( 'disabled', true );
        $btnPg.prop( 'disabled', true );
        $btnClear.prop( 'disabled', true );

        $.post( cacbAdmin.ajaxUrl, {
            action      : 'cacb_rag_index_batch',
            nonce       : nonce,
            object_type : type,
            offset      : offset
        }, function ( res ) {
            if ( ! res.success ) {
                $text.html( '<span style="color:#d63638">❌ ' + i18n.indexError + '</span>' );
                $btnP.prop( 'disabled', false );
                $btnPg.prop( 'disabled', false );
                $btnClear.prop( 'disabled', false );
                return;
            }

            var d    = res.data;
            var pct  = d.total > 0 ? Math.round( ( d.offset / d.total ) * 100 ) : 100;
            pct      = Math.min( 100, pct );
            $bar.css( 'width', pct + '%' );
            $text.text( i18n.indexing + ' ' + Math.min( d.offset, d.total ) + ' / ' + d.total );

            if ( d.errors && d.errors.length ) {
                $text.append( ' — <span style="color:#d63638">Σφάλματα: ' + esc( d.errors.join( ', ' ) ) + '</span>' );
            }

            if ( d.done ) {
                $bar.css( 'width', '100%' );
                $text.html( '<span style="color:#1e6637">✅ ' + i18n.indexDone + ' (' + d.offset + ' αντικείμενα)</span>' );
                $btnP.prop( 'disabled', false );
                $btnPg.prop( 'disabled', false );
                $btnClear.prop( 'disabled', false );
                loadRagStatus();
            } else {
                runBatchIndex( type, d.offset );
            }
        } ).fail( function () {
            $text.html( '<span style="color:#d63638">❌ Network error</span>' );
            $btnP.prop( 'disabled', false );
            $btnPg.prop( 'disabled', false );
            $btnClear.prop( 'disabled', false );
        } );
    }

    // ── RAG: button handlers ──────────────────────────────────────────────────
    $( '#cacb-rag-index-products' ).on( 'click', function () {
        $( '#cacb-rag-progress-bar' ).css( 'width', '0%' );
        $( '#cacb-rag-progress-text' ).text( '' );
        runBatchIndex( 'product', 0 );
    } );

    $( '#cacb-rag-index-pages' ).on( 'click', function () {
        $( '#cacb-rag-progress-bar' ).css( 'width', '0%' );
        $( '#cacb-rag-progress-text' ).text( '' );
        runBatchIndex( 'page', 0 );
    } );

    $( '#cacb-rag-clear' ).on( 'click', function () {
        if ( ! window.confirm( i18n.confirmClearIndex ) ) return;
        var $btn = $( this );
        $btn.prop( 'disabled', true );
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_clear', nonce: nonce }, function ( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) {
                $( '#cacb-rag-progress-wrap' ).hide();
                loadRagStatus();
                alert( i18n.cleared );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
