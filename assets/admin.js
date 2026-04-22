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
            var d = res.data;
            var $indexed = $( '#cacb-rag-indexed-wrap' );

            if ( d.provider === 'none' ) {
                $ragStatus.html(
                    '<div class="cacb-notice cacb-notice--warn">⚠️ Δεν υπάρχει embedding API key. '
                    + 'Βεβαιώσου ότι το API key του ενεργού provider είναι αποθηκευμένο (ή πρόσθεσε OpenAI key αν χρησιμοποιείς Claude).</div>'
                );
                $indexed.empty();
                return;
            }

            // Summary line
            var summary = '<p style="margin:0;font-size:13px">'
                + '<strong>Provider:</strong> ' + esc( d.provider )
                + '  &nbsp;·&nbsp;  <strong>Σελίδες:</strong> ' + d.indexed_pages + ' / ' + d.total_pages;
            if ( d.last_indexed ) {
                summary += '  &nbsp;·&nbsp;  <strong>Τελευταία ενημέρωση:</strong> πριν ' + esc( d.last_indexed );
            }
            summary += '</p>';
            $ragStatus.html( summary );

            // List of indexed pages
            var items = d.items || [];
            if ( ! items.length ) {
                $indexed.html( '<p style="color:#888;font-style:italic;margin:0">Καμία σελίδα δεν έχει ευρετηριαστεί ακόμα.</p>' );
                return;
            }

            var listHtml = '<h3 style="margin:0 0 8px;font-size:13px;color:#555">Ευρετηριασμένες σελίδες</h3><ul style="margin:0;padding:0;list-style:none">';
            items.forEach( function ( it ) {
                listHtml += '<li style="padding:6px 0;border-bottom:1px solid #f0f0f1">'
                    + '<a href="' + esc( it.url ) + '" target="_blank" rel="noopener" '
                    + 'style="color:#2271b1;text-decoration:none;font-weight:500">'
                    + esc( it.title ) + '</a>'
                    + ' <span style="color:#8c8f94;font-size:12px">(' + it.chunks + ' chunks)</span>'
                    + '<br><span style="color:#8c8f94;font-size:11px;word-break:break-all">' + esc( it.url ) + '</span>'
                    + '</li>';
            } );
            listHtml += '</ul>';
            $indexed.html( listHtml );
        } );
    }

    function esc( str ) {
        return $( '<div>' ).text( String( str ) ).html();
    }

    // ── RAG: batch index helper ───────────────────────────────────────────────
    function runBatchIndex( type, offset, totalIndexed, totalErrors, firstError ) {
        totalIndexed = totalIndexed || 0;
        totalErrors  = totalErrors  || 0;
        firstError   = firstError   || '';

        var $bar      = $( '#cacb-rag-progress-bar' );
        var $text     = $( '#cacb-rag-progress-text' );
        var $wrap     = $( '#cacb-rag-progress-wrap' );
        var $btnPg    = $( '#cacb-rag-index-pages' );
        var $btnClear = $( '#cacb-rag-clear' );

        $wrap.show();
        $btnPg.prop( 'disabled', true );
        $btnClear.prop( 'disabled', true );

        $.post( cacbAdmin.ajaxUrl, {
            action      : 'cacb_rag_index_batch',
            nonce       : nonce,
            object_type : type,
            offset      : offset
        }, function ( res ) {
            if ( ! res.success ) {
                $text.html( '<span style="color:#d63638">❌ ' + esc( ( res.data && res.data.message ) || i18n.indexError ) + '</span>' );
                $btnPg.prop( 'disabled', false );
                $btnClear.prop( 'disabled', false );
                return;
            }

            var d = res.data;

            // Accumulate counts across batches
            totalIndexed += ( d.indexed || 0 );
            if ( d.errors && d.errors.length ) {
                totalErrors += d.errors.length;
                if ( ! firstError ) {
                    firstError = d.errors[0];
                }
            }

            var pct = d.total > 0 ? Math.round( ( ( offset + ( d.indexed || 0 ) ) / d.total ) * 100 ) : 100;
            pct     = Math.min( 99, pct ); // keep at 99% until truly done
            $bar.css( 'width', pct + '%' );
            $text.text( i18n.indexing + ' ' + Math.min( d.offset, d.total ) + ' / ' + d.total );

            if ( d.done ) {
                $bar.css( 'width', '100%' );

                if ( totalIndexed === 0 && totalErrors > 0 ) {
                    // Nothing succeeded — surface the real error instead of ✅
                    var errMsg = firstError || i18n.indexError;
                    $text.html( '<span style="color:#d63638">❌ ' + esc( errMsg ) + '</span>' );
                } else if ( totalErrors > 0 ) {
                    // Partial success
                    $text.html(
                        '<span style="color:#1e6637">✅ ' + i18n.indexDone + ' (' + totalIndexed + ' αντικείμενα)</span>'
                        + ' <span style="color:#d63638">— ' + totalErrors + ' σφάλματα</span>'
                    );
                } else {
                    $text.html( '<span style="color:#1e6637">✅ ' + i18n.indexDone + ' (' + totalIndexed + ' αντικείμενα)</span>' );
                }

                $btnPg.prop( 'disabled', false );
                $btnClear.prop( 'disabled', false );
                loadRagStatus();
                loadIndexedList();
            } else {
                runBatchIndex( type, d.offset, totalIndexed, totalErrors, firstError );
            }
        } ).fail( function () {
            $text.html( '<span style="color:#d63638">❌ Network error</span>' );
            $btnPg.prop( 'disabled', false );
            $btnClear.prop( 'disabled', false );
        } );
    }

    // ── RAG: button handlers ──────────────────────────────────────────────────
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
                loadIndexedList();
                alert( i18n.cleared );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
