/**
 * Capitano AI Chatbot — Frontend JS
 * Handles UI interactions, conversation chatHistory, and API communication.
 */
( function () {
    'use strict';

    const cfg = window.cacbConfig || {};

    // ── DOM refs ──────────────────────────────────────────────────────────────
    const wrapper    = document.getElementById( 'cacb-wrapper' );
    const bubble     = document.getElementById( 'cacb-bubble' );
    const win        = document.getElementById( 'cacb-window' );
    const msgList    = document.getElementById( 'cacb-messages' );
    const typingEl   = document.getElementById( 'cacb-typing' );
    const inputEl    = document.getElementById( 'cacb-input' );
    const sendBtn    = document.getElementById( 'cacb-send' );
    const closeBtn   = document.getElementById( 'cacb-close-btn' );
    const clearBtn   = document.getElementById( 'cacb-clear-btn' );
    const iconOpen   = document.getElementById( 'cacb-icon-open' );
    const iconClose  = document.getElementById( 'cacb-icon-close' );

    if ( ! wrapper ) return; // Safety guard

    // ── State ─────────────────────────────────────────────────────────────────
    const STORAGE_KEY = 'cacb_chatHistory';
    let chatHistory       = loadHistory();
    let isOpen        = false;
    let isSending     = false;
    let initialized   = false;

    // ── History persistence (localStorage) ───────────────────────────────────
    function loadHistory() {
        try {
            const stored = JSON.parse( localStorage.getItem( STORAGE_KEY ) );
            return Array.isArray( stored ) ? stored : []; // guard against corrupt data
        } catch {
            return [];
        }
    }

    function saveHistory() {
        try {
            localStorage.setItem( STORAGE_KEY, JSON.stringify( chatHistory ) );
        } catch {
            // localStorage unavailable — graceful degradation
        }
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
            // Show welcome message if no chatHistory
            if ( chatHistory.length === 0 ) {
                appendMessage( 'bot', cfg.welcomeMessage || '' );
            } else {
                // Restore previous conversation
                chatHistory.forEach( msg => appendMessage( msg.role === 'user' ? 'user' : 'bot', msg.content, false ) );
            }
        }

        // Focus input after transition
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

    // ── Message rendering ─────────────────────────────────────────────────────
    function appendMessage( role, text, animate = true ) {
        const row = document.createElement( 'div' );
        row.className = 'cacb-msg cacb-msg--' + role + ( animate ? ' cacb-msg--in' : '' );

        const bubble_el = document.createElement( 'div' );
        bubble_el.className = 'cacb-bubble';

        if ( role === 'user' ) {
            // User input: always escape to prevent XSS
            bubble_el.innerHTML = escapeHtml( text ).replace( /\n/g, '<br>' );
        } else {
            // Bot reply: already sanitized server-side via wp_kses — render as HTML
            bubble_el.innerHTML = text.replace( /\n/g, '<br>' );
        }

        row.appendChild( bubble_el );
        msgList.appendChild( row );
        scrollToBottom();
        return row;
    }

    function escapeHtml( str ) {
        const div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str ) );
        return div.innerHTML;
    }

    function scrollToBottom() {
        msgList.scrollTop = msgList.scrollHeight;
    }

    // ── Typing indicator ──────────────────────────────────────────────────────
    function showTyping()  { typingEl.hidden = false; scrollToBottom(); }
    function hideTyping()  { typingEl.hidden = true; }

    // ── Send message (streaming via SSE) ─────────────────────────────────────
    async function sendMessage() {
        const text = inputEl.value.trim();
        if ( ! text || isSending ) return;

        inputEl.value = '';
        autoResize();
        appendMessage( 'user', text );
        isSending = true;
        sendBtn.disabled = true;
        showTyping();

        chatHistory.push( { role: 'user', content: text } );
        saveHistory();

        let fullReply = '';
        let botBubble = null; // created on first chunk

        try {
            const body = new URLSearchParams( {
                action:   'cacb_stream',
                nonce:    cfg.nonce,
                messages: JSON.stringify( chatHistory ),
            } );

            const res = await fetch( cfg.streamUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            } );

            // If SSE not supported or request failed, fall back to REST endpoint
            if ( ! res.ok || ! res.body ) {
                const data = await res.json().catch( () => ({}) );
                appendMessage( 'bot', data.message || cfg.errorMessage );
                return;
            }

            const reader     = res.body.getReader();
            const decoder    = new TextDecoder();
            let   buffer     = '';
            let   streamDone = false; // flag shared between outer while and inner for

            while ( ! streamDone ) {
                const { done, value } = await reader.read();
                if ( done ) break;

                buffer += decoder.decode( value, { stream: true } );

                // SSE events are separated by double newlines
                const parts = buffer.split( '\n\n' );
                buffer = parts.pop(); // keep last incomplete chunk

                for ( const part of parts ) {
                    const line = part.trim();
                    if ( ! line.startsWith( 'data: ' ) ) continue;

                    const payload = line.slice( 6 ).trim();
                    if ( payload === '[DONE]' ) {
                        streamDone = true; // stops outer while too
                        break;
                    }

                    let parsed;
                    try { parsed = JSON.parse( payload ); } catch { continue; }

                    if ( parsed.e ) {
                        // Error from server — stop reading
                        hideTyping();
                        if ( botBubble ) botBubble.innerHTML = escapeHtml( parsed.e );
                        else appendMessage( 'bot', parsed.e );
                        streamDone = true;
                        break;
                    }

                    if ( parsed.t ) {
                        // First chunk: hide typing dots and create bot bubble
                        if ( ! botBubble ) {
                            hideTyping();
                            const row = appendMessage( 'bot', '', true );
                            botBubble = row.querySelector( '.cacb-bubble' );
                        }
                        fullReply += parsed.t;
                        botBubble.innerHTML = escapeHtml( fullReply ).replace( /\n/g, '<br>' );
                        scrollToBottom();
                    }
                }
            }

            if ( fullReply ) {
                chatHistory.push( { role: 'assistant', content: fullReply } );
                saveHistory();
                logExchange( text, fullReply ); // fire-and-forget
            } else if ( ! botBubble ) {
                // Stream ended with no text and no error shown
                appendMessage( 'bot', cfg.errorMessage );
            }

        } catch ( err ) {
            hideTyping();
            if ( botBubble ) botBubble.innerHTML = escapeHtml( cfg.errorMessage );
            else appendMessage( 'bot', cfg.errorMessage );
        } finally {
            hideTyping();
            isSending = false;
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

    // ── Fire-and-forget: log exchange after stream completes ─────────────────
    function logExchange( userMsg, botReply ) {
        fetch( cfg.streamUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams( {
                action:    'cacb_log',
                nonce:     cfg.nonce,
                user_msg:  userMsg,
                bot_reply: botReply,
            } ),
        } ).catch( function () {} ); // silent — log failure must never affect the user
    }

    // ── Auto-resize textarea ──────────────────────────────────────────────────
    function autoResize() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min( inputEl.scrollHeight, 120 ) + 'px';
    }

    // ── Event listeners ───────────────────────────────────────────────────────
    bubble.addEventListener( 'click', ( e ) => {
        e.stopPropagation();
        isOpen ? closeChat() : openChat();
    } );

    closeBtn.addEventListener( 'click', ( e ) => {
        e.stopPropagation();
        closeChat();
    } );

    if ( clearBtn ) {
        clearBtn.addEventListener( 'click', ( e ) => {
            e.stopPropagation();
            clearChat();
        } );
    }

    sendBtn.addEventListener( 'click', sendMessage );

    inputEl.addEventListener( 'keydown', ( e ) => {
        // Send on Enter (not Shift+Enter)
        if ( e.key === 'Enter' && ! e.shiftKey ) {
            e.preventDefault();
            sendMessage();
        }
    } );

    inputEl.addEventListener( 'input', autoResize );

    // Close on Escape
    document.addEventListener( 'keydown', ( e ) => {
        if ( e.key === 'Escape' && isOpen ) closeChat();
    } );

    // Close on outside click
    document.addEventListener( 'click', ( e ) => {
        if ( isOpen && ! wrapper.contains( e.target ) ) {
            closeChat();
        }
    } );

    // ── Pulse animation after 3s — removed after all 3 iterations complete ───
    setTimeout( () => {
        bubble.classList.add( 'cacb-pulse' );
        bubble.addEventListener(
            'animationend',
            () => bubble.classList.remove( 'cacb-pulse' ),
            { once: true }
        );
    }, 3000 );

} )();
