/**
 * Capitano AI Chatbot — Frontend JS
 * Handles UI interactions, conversation history, and API communication.
 */
( function () {
    'use strict';

    const cfg = window.cacbConfig || {};

    // ── DOM refs ──────────────────────────────────────────────────────────────
    const wrapper   = document.getElementById( 'cacb-wrapper' );
    const bubble    = document.getElementById( 'cacb-bubble' );
    const win       = document.getElementById( 'cacb-window' );
    const msgList   = document.getElementById( 'cacb-messages' );
    const typingEl  = document.getElementById( 'cacb-typing' );
    const inputEl   = document.getElementById( 'cacb-input' );
    const sendBtn   = document.getElementById( 'cacb-send' );
    const closeBtn  = document.getElementById( 'cacb-close-btn' );
    const clearBtn  = document.getElementById( 'cacb-clear-btn' );
    const iconOpen  = document.getElementById( 'cacb-icon-open' );
    const iconClose = document.getElementById( 'cacb-icon-close' );

    if ( ! wrapper ) return;

    // ── State ─────────────────────────────────────────────────────────────────
    const STORAGE_KEY = 'cacb_chatHistory';
    let chatHistory = loadHistory();
    let isOpen      = false;
    let isSending   = false;
    let initialized = false;

    // ── History persistence ───────────────────────────────────────────────────
    function loadHistory() {
        try {
            const stored = JSON.parse( localStorage.getItem( STORAGE_KEY ) );
            return Array.isArray( stored ) ? stored : [];
        } catch { return []; }
    }

    function saveHistory() {
        try { localStorage.setItem( STORAGE_KEY, JSON.stringify( chatHistory ) ); }
        catch {} // graceful degradation
    }

    // ── Open / close ──────────────────────────────────────────────────────────
    function openChat() {
        isOpen = true;
        win.hidden = false;
        bubble.setAttribute( 'aria-expanded', 'true' );
        iconOpen.style.display  = 'none';
        iconClose.style.display = '';

        if ( ! initialized ) {
            initialized = true;
            if ( chatHistory.length === 0 ) {
                appendMessage( 'bot', cfg.welcomeMessage || '' );
            } else {
                // Restore history — strip product markers (cards won't re-render)
                chatHistory.forEach( msg => appendMessage(
                    msg.role === 'user' ? 'user' : 'bot',
                    msg.content.replace( /\[PRODUCT:\d+\]/g, '' ).trim(),
                    false
                ) );
            }
        }

        setTimeout( () => inputEl.focus(), 200 );
        scrollToBottom();
    }

    function closeChat() {
        isOpen = false;
        win.hidden = true;
        bubble.setAttribute( 'aria-expanded', 'false' );
        iconOpen.style.display  = '';
        iconClose.style.display = 'none';
        bubble.focus();
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function escapeHtml( str ) {
        const div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str || '' ) );
        return div.innerHTML;
    }

    function scrollToBottom() {
        msgList.scrollTop = msgList.scrollHeight;
    }

    // ── Minimal markdown renderer ─────────────────────────────────────────────
    function renderMarkdown( text ) {
        let html = escapeHtml( text );

        html = html.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
        html = html.replace( /(?<!\*)\*([^*\n]+?)\*(?!\*)/g, '<em>$1</em>' );

        const lines  = html.split( '\n' );
        const parts  = [];
        let   inList = false;

        for ( const line of lines ) {
            const m = line.match( /^[-*•]\s+(.+)/ );
            if ( m ) {
                if ( ! inList ) { parts.push( '<ul>' ); inList = true; }
                parts.push( '<li>' + m[1] + '</li>' );
            } else {
                if ( inList ) { parts.push( '</ul>' ); inList = false; }
                parts.push( line );
            }
        }
        if ( inList ) parts.push( '</ul>' );

        return parts.join( '\n' )
            .replace( /\n/g, '<br>' )
            .replace( /<br>(<\/?(?:ul|li)>)/g, '$1' )
            .replace( /(<\/(?:ul|li)>)<br>/g, '$1' );
    }

    // ── Append a plain message bubble ─────────────────────────────────────────
    function appendMessage( role, text, animate = true ) {
        const row = document.createElement( 'div' );
        row.className = 'cacb-msg cacb-msg--' + role + ( animate ? ' cacb-msg--in' : '' );

        const bbl = document.createElement( 'div' );
        bbl.className = 'cacb-bubble';
        bbl.innerHTML = role === 'user'
            ? escapeHtml( text ).replace( /\n/g, '<br>' )
            : renderMarkdown( text );

        row.appendChild( bbl );
        msgList.appendChild( row );
        scrollToBottom();
        return row;
    }

    // ── Append bot message with optional product cards ────────────────────────
    function appendBotMessage( text ) {
        // Split on [PRODUCT:123] markers — odd indexes are product IDs
        const parts = text.split( /\[PRODUCT:(\d+)\]/g );
        const row   = document.createElement( 'div' );
        row.className = 'cacb-msg cacb-msg--bot cacb-msg--in';
        msgList.appendChild( row );

        for ( let i = 0; i < parts.length; i++ ) {
            if ( i % 2 === 0 ) {
                // Text segment
                const seg = parts[ i ].replace( /^\n+|\n+$/g, '' );
                if ( seg ) {
                    const bbl = document.createElement( 'div' );
                    bbl.className = 'cacb-bubble';
                    bbl.innerHTML = renderMarkdown( seg );
                    row.appendChild( bbl );
                }
            } else {
                // Product ID → create placeholder and fetch asynchronously
                const id = parseInt( parts[ i ], 10 );
                const ph = document.createElement( 'div' );
                ph.className = 'cacb-card-placeholder';
                row.appendChild( ph );
                fetchProductCard( ph, id );
            }
        }

        scrollToBottom();
    }

    // ── Fetch product data and replace placeholder with card ──────────────────
    function fetchProductCard( placeholder, id ) {
        if ( ! cfg.productUrl ) { placeholder.remove(); return; }

        fetch( cfg.productUrl + id )
            .then( function ( res ) {
                if ( ! res.ok ) { placeholder.remove(); return null; }
                return res.json();
            } )
            .then( function ( p ) {
                if ( ! p ) return;
                const card = document.createElement( 'div' );
                card.className = 'cacb-product-card';
                card.innerHTML = buildCardHtml( p );
                placeholder.replaceWith( card );
                scrollToBottom();
            } )
            .catch( function () { placeholder.remove(); } );
    }

    // ── Build product card inner HTML ─────────────────────────────────────────
    function buildCardHtml( p ) {
        const onSale = p.sale_price && p.regular_price &&
            parseFloat( p.sale_price ) < parseFloat( p.regular_price );

        const imgHtml = p.image
            ? '<img class="cacb-card-img" src="' + escapeHtml( p.image ) + '" alt="' + escapeHtml( p.name ) + '" loading="lazy">'
            : '';

        const priceHtml = onSale
            ? '<span class="cacb-card-sale">' + escapeHtml( p.price ) + '€</span>'
              + '<s class="cacb-card-regular">' + escapeHtml( p.regular_price ) + '€</s>'
            : '<span class="cacb-card-price">' + escapeHtml( p.price ) + '€</span>';

        const url = /^https?:\/\//.test( p.url ) ? p.url : '#';

        return imgHtml
            + '<div class="cacb-card-body">'
            + '<div class="cacb-card-name">' + escapeHtml( p.name ) + '</div>'
            + '<div class="cacb-card-prices">' + priceHtml + '</div>'
            + '<a href="' + escapeHtml( url ) + '" class="cacb-card-btn" target="_blank" rel="noopener noreferrer">Προβολή Προϊόντος</a>'
            + '</div>';
    }

    // ── Typing indicator ──────────────────────────────────────────────────────
    function showTyping() { typingEl.hidden = false; scrollToBottom(); }
    function hideTyping() { typingEl.hidden = true; }

    // ── Send message via REST API ─────────────────────────────────────────────
    async function sendMessage() {
        const text = inputEl.value.trim();
        if ( ! text || isSending ) return;

        inputEl.value = '';
        autoResize();
        appendMessage( 'user', text );
        isSending        = true;
        sendBtn.disabled = true;
        showTyping();

        chatHistory.push( { role: 'user', content: text } );
        saveHistory();

        try {
            const res = await fetch( cfg.apiUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify( { messages: chatHistory, nonce: cfg.nonce } ),
            } );

            const data = await res.json();
            hideTyping();

            if ( ! res.ok || data.code ) {
                appendMessage( 'bot', data.message || cfg.errorMessage );
                return;
            }

            const reply = ( data.reply || '' ).trim();
            if ( reply ) {
                chatHistory.push( { role: 'assistant', content: reply } );
                saveHistory();
                appendBotMessage( reply );
            } else {
                appendMessage( 'bot', cfg.errorMessage );
            }

        } catch {
            hideTyping();
            appendMessage( 'bot', cfg.errorMessage );
        } finally {
            hideTyping();
            isSending        = false;
            sendBtn.disabled = false;
            inputEl.focus();
        }
    }

    // ── Clear conversation ────────────────────────────────────────────────────
    function clearChat() {
        chatHistory = [];
        saveHistory();
        msgList.innerHTML = '';
        appendMessage( 'bot', cfg.welcomeMessage || '' );
    }

    // ── Auto-resize textarea ──────────────────────────────────────────────────
    function autoResize() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min( inputEl.scrollHeight, 120 ) + 'px';
    }

    // ── Event listeners ───────────────────────────────────────────────────────
    bubble.addEventListener( 'click', e => { e.stopPropagation(); isOpen ? closeChat() : openChat(); } );
    closeBtn.addEventListener( 'click', e => { e.stopPropagation(); closeChat(); } );
    if ( clearBtn ) clearBtn.addEventListener( 'click', e => { e.stopPropagation(); clearChat(); } );
    sendBtn.addEventListener( 'click', sendMessage );
    inputEl.addEventListener( 'keydown', e => {
        if ( e.key === 'Enter' && ! e.shiftKey ) { e.preventDefault(); sendMessage(); }
    } );
    inputEl.addEventListener( 'input', autoResize );
    document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && isOpen ) closeChat(); } );
    document.addEventListener( 'click', e => { if ( isOpen && ! wrapper.contains( e.target ) ) closeChat(); } );

    // ── Pulse animation after 3s ──────────────────────────────────────────────
    setTimeout( () => {
        bubble.classList.add( 'cacb-pulse' );
        bubble.addEventListener( 'animationend', () => bubble.classList.remove( 'cacb-pulse' ), { once: true } );
    }, 3000 );

} )();
