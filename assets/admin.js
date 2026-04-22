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

    // ── RAG: index status + indexed list ──────────────────────────────────────
    var $ragStats = $( '#cacb-rag-stats' );
    if ( $ragStats.length ) {
        loadRagStatus();
        loadIndexedList();
    }

    function loadRagStatus() {
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_status', nonce: nonce }, function ( res ) {
            if ( ! res.success ) return;
            var d = res.data;

            if ( d.provider === 'none' ) {
                $ragStats.html(
                    '<div class="cacb-notice cacb-notice--warn" style="grid-column:1/-1">⚠️ Δεν υπάρχει embedding API key. '
                    + 'Βεβαιώσου ότι το API key του ενεργού provider είναι αποθηκευμένο (ή πρόσθεσε OpenAI key αν χρησιμοποιείς Claude).</div>'
                );
                return;
            }

            var coverage = d.total_pages > 0
                ? Math.round( ( d.indexed_pages / d.total_pages ) * 100 )
                : 0;
            var coverageClass = coverage === 100 ? 'cacb-stat--ok'
                              : coverage >= 50   ? 'cacb-stat--warn'
                              :                    'cacb-stat--err';

            var html = ''
                + statCard( 'Provider', esc( d.provider ).toUpperCase(), null, '' )
                + statCard( 'Σελίδες στον Index', d.indexed_pages + ' / ' + d.total_pages,
                           coverage + '% κάλυψη', coverageClass )
                + statCard( 'Τελευταία ενημέρωση',
                           d.last_indexed ? 'πριν ' + esc( d.last_indexed ) : '—',
                           d.last_indexed ? '' : 'Κανένα index ακόμα',
                           d.last_indexed ? 'cacb-stat--ok' : 'cacb-stat--warn' );

            $ragStats.html( html );
        } );
    }

    function statCard( label, value, sub, cls ) {
        return '<div class="cacb-stat-card ' + ( cls || '' ) + '">'
            + '<div class="cacb-stat-label">' + esc( label ) + '</div>'
            + '<div class="cacb-stat-value">' + value + '</div>'
            + ( sub ? '<div class="cacb-stat-sub">' + esc( sub ) + '</div>' : '' )
            + '</div>';
    }

    function loadIndexedList() {
        var $list = $( '#cacb-rag-indexed-list' );
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_list_indexed', nonce: nonce }, function ( res ) {
            if ( ! res.success ) {
                $list.html( '<p style="color:#d63638">Σφάλμα φόρτωσης λίστας.</p>' );
                return;
            }
            var items = res.data.items || [];
            if ( ! items.length ) {
                $list.html( '<p style="color:#888;font-style:italic">Καμία σελίδα δεν έχει ευρετηριαστεί ακόμα.</p>' );
                return;
            }

            var rows = items.map( function ( it ) {
                return '<tr data-id="' + it.id + '">'
                    + '<td><strong>' + esc( it.title ) + '</strong><br>'
                    + '<a href="' + esc( it.url ) + '" target="_blank" rel="noopener" class="cacb-url-link">'
                    + esc( it.url ) + '</a></td>'
                    + '<td><span class="cacb-chip">' + it.chunks + ' chunks</span></td>'
                    + '<td style="color:#646970">πριν ' + esc( it.ago ) + '</td>'
                    + '<td><div class="cacb-row-actions">'
                    + '<button type="button" class="button cacb-reindex-one" title="Re-index">↻</button>'
                    + '<button type="button" class="button cacb-btn-danger cacb-remove-one" title="Αφαίρεση">✕</button>'
                    + '</div></td>'
                    + '</tr>';
            } ).join( '' );

            $list.html(
                '<table class="cacb-indexed-table">'
                + '<thead><tr>'
                + '<th>Σελίδα / URL</th>'
                + '<th style="width:100px">Chunks</th>'
                + '<th style="width:140px">Ενημέρωση</th>'
                + '<th style="width:90px">Ενέργειες</th>'
                + '</tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>'
            );
        } );
    }

    // Per-row actions (delegated)
    $( document ).on( 'click', '.cacb-reindex-one', function () {
        var $row = $( this ).closest( 'tr' );
        var id   = $row.data( 'id' );
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_reindex_one', nonce: nonce, id: id }, function ( res ) {
            $btn.prop( 'disabled', false ).text( '↻' );
            if ( res.success ) {
                loadRagStatus();
                loadIndexedList();
            } else {
                alert( 'Σφάλμα: ' + ( res.data && res.data.message || 'unknown' ) );
            }
        } );
    } );

    $( document ).on( 'click', '.cacb-remove-one', function () {
        if ( ! window.confirm( 'Αφαίρεση από τον index;' ) ) return;
        var $row = $( this ).closest( 'tr' );
        var id   = $row.data( 'id' );
        $.post( cacbAdmin.ajaxUrl, { action: 'cacb_rag_remove_one', nonce: nonce, id: id }, function ( res ) {
            if ( res.success ) {
                $row.fadeOut( 200, function () {
                    $row.remove();
                    loadRagStatus();
                    loadIndexedList();
                } );
            }
        } );
    } );

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
