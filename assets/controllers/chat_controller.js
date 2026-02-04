import { Controller } from '@hotwired/stimulus';

/**
 * Synapse Chat Controller (v2 - Refactored)
 *
 * Handles the chat UI: sending messages, receiving streaming responses,
 * rendering markdown, and displaying thinking/debug blocks.
 *
 * Agnostic: No hardcoded texts (uses data-defaults or attributes).
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submitBtn', 'personaSelect', 'container', 'greeting'];
    static values = {
        history: Array,
        debug: { type: Boolean, default: false },
        welcomeMessage: { type: String, default: '' } // Allow overriding "New Conversation" toast
    };

    connect() {
        this.scrollToBottom();
        this.historyLoaded = false;
        this.inputTarget.focus();

        // Check for debug mode in URL
        const urlParams = new URLSearchParams(window.location.search);
        this.isDebugMode = urlParams.has('debug') || this.debugValue;

        if (this.isDebugMode) {
            this.element.classList.add('synapse-chat--debug-mode');
        }

        // Load marked async (for streaming)
        this.loadMarked();
    }

    // History is now rendered server-side via Twig
    loadHistory(history) {
        // Kept empty or remove completely if no longer needed
        // but removing it requires removing calls to it.
        // Since we removed the call in connect(), we can remove this method or keep it simple
        console.log('📜 [History] Managed by Server-Side Rendering');
        this.scrollToBottom();
    }

    handleKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send(event);
        }
    }

    async send(event) {
        event.preventDefault();

        const message = this.inputTarget.value.trim();
        if (!message) return;

        // Switch UI to chat mode immediately
        if (this.hasContainerTarget) {
            this.containerTarget.classList.remove('mode-welcome');
            this.containerTarget.classList.add('mode-chat');
        }
        if (this.hasGreetingTarget) {
            this.greetingTarget.classList.add('hidden');
        }

        this.addMessage(message, 'user');
        this.inputTarget.value = '';
        this.inputTarget.style.height = 'auto';
        this.setLoading(true);

        // Get Persona
        let persona = null;
        if (this.hasPersonaSelectTarget) {
            persona = this.personaSelectTarget.value;
        }

        // Get current conversation ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const conversationId = urlParams.get('conversation');

        try {
            const response = await fetch('/synapse/api/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    conversation_id: conversationId,
                    options: { persona: persona },
                    debug: this.isDebugMode
                })
            });

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let currentResponseText = '';
            let currentMessageBubble = null;

            // Safety timeout (30 seconds)
            const streamTimeout = setTimeout(() => {
                reader.cancel();
                this.setLoading(false);
                this.addMessage('⏱️ Timeout: Le serveur ne répond plus. Veuillez réessayer.', 'assistant');
                console.error('🔴 [Stream] Timeout after 30 seconds');
            }, 30000);

            try {

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.trim()) continue;

                        const trimmedLine = line.trim();
                        if (!trimmedLine.startsWith('{') && !trimmedLine.startsWith('[')) {
                            continue;
                        }

                        try {
                            const evt = JSON.parse(trimmedLine);

                            // Validate event structure
                            if (!evt || typeof evt !== 'object' || !evt.type) {
                                console.warn('⚠️ [Stream] Invalid event structure:', evt);
                                continue;
                            }

                            if (evt.type === 'status') {
                                if (evt.payload && evt.payload.message) {
                                    this.updateLoadingStatus(evt.payload.message);
                                }
                            } else if (evt.type === 'delta') {
                                // First token received: stop loading animation
                                if (!currentMessageBubble) {
                                    this.setLoading(false);
                                    // Create the message bubble container manually to hold the stream
                                    this.addMessage('', 'assistant');
                                    const messages = this.messagesTarget.querySelectorAll('.synapse-chat__message--assistant');
                                    const lastMsg = messages[messages.length - 1];
                                    currentMessageBubble = lastMsg.querySelector('.synapse-chat__bubble');
                                }

                                if (evt.payload && evt.payload.text) {
                                    currentResponseText += evt.payload.text;
                                    currentMessageBubble.innerHTML = this.parseMarkdown(currentResponseText);
                                    this.scrollToBottom();
                                }

                            } else if (evt.type === 'result') {
                                this.setLoading(false);

                                // If we streamed text, ensure final consistency (sometimes helpful for incomplete markdown)
                                if (currentMessageBubble && evt.payload && evt.payload.answer) {
                                    currentMessageBubble.innerHTML = this.parseMarkdown(evt.payload.answer);
                                    // Add debug footer if needed
                                    if (evt.payload.conversation_id) {
                                        this.updateUrlWithConversationId(evt.payload.conversation_id);
                                    }

                                    // Re-inject debug button if in debug mode
                                    if (this.isDebugMode && evt.payload.debug_id) {
                                        const debugUrl = `/synapse/_debug/${evt.payload.debug_id}`;
                                        const debugHtml = `
                                        <button type="button" class="synapse-chat__debug-trigger"
                                                onclick="window.open('${debugUrl}', 'SynapseDebug', 'width=1000,height=900')"
                                                title="Debug">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                        </button>
                                    `;
                                        // wrapper footer
                                        const footer = document.createElement('div');
                                        footer.className = 'synapse-chat__footer';
                                        footer.innerHTML = debugHtml;

                                        // Find parent .synapse-chat__content and append footer
                                        currentMessageBubble.closest('.synapse-chat__content').appendChild(footer);
                                    }
                                } else if (evt.payload && evt.payload.answer) {
                                    // Fallback if no delta was received (e.g. empty response or error handled as result)
                                    this.addMessage(evt.payload.answer, 'assistant', evt.payload);
                                }

                            } else if (evt.type === 'title') {
                                // Auto-generated title received
                                const conversationId = new URLSearchParams(window.location.search).get('conversation');
                                if (conversationId && evt.payload && evt.payload.title) {
                                    document.dispatchEvent(new CustomEvent('assistant:title-updated', {
                                        detail: { conversationId, title: evt.payload.title }
                                    }));
                                }
                            } else if (evt.type === 'error') {
                                const errorMsg = evt.payload || evt.message || 'Unknown error';
                                throw new Error(errorMsg);
                            } else {
                                console.warn('⚠️ [Stream] Unknown event type:', evt.type);
                            }
                        } catch (e) {
                            if (e instanceof SyntaxError) {
                                console.warn('⚠️ [Stream] Invalid JSON:', trimmedLine.substring(0, 100));
                            } else {
                                console.error('🔴 [Stream] Processing error:', e);
                                // Don't throw - continue processing other events
                            }
                        }
                    }
                }
            } finally {
                clearTimeout(streamTimeout);
            }

        } catch (error) {
            this.setLoading(false);
            this.addMessage('❌ Erreur: ' + error.message, 'assistant');
            console.error('🔴 [Stream] Fatal error:', error);
        } finally {
            this.setLoading(false);
            this.inputTarget.focus();
        }
    }

    updateLoadingStatus(message) {
        const loadingContent = this.messagesTarget.querySelector('#synapse-loading .synapse-chat__content');
        if (loadingContent) {
            loadingContent.innerHTML = `<span class="synapse-chat__typing-dots">${message}</span>`;
            this.scrollToBottom();
        }
    }

    autoResize(event) {
        const textarea = event ? event.target : this.inputTarget;
        textarea.style.height = 'auto';

        const maxHeight = 120;
        const newHeight = Math.min(textarea.scrollHeight, maxHeight);
        textarea.style.height = newHeight + 'px';
        textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';
    }

    async newConversation() {
        try {
            const response = await fetch('/synapse/api/reset', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const data = await response.json();

            if (data.success) {
                // Clear all messages
                this.messagesTarget.querySelectorAll('.synapse-chat__message').forEach(m => m.remove());

                // Restore greeting
                if (this.hasGreetingTarget) {
                    this.greetingTarget.classList.remove('hidden');
                }

                // Restore welcome mode
                if (this.hasContainerTarget) {
                    this.containerTarget.classList.remove('mode-chat');
                    this.containerTarget.classList.add('mode-welcome');
                }

                // Optional: Toast or message if no greeting target
                if (!this.hasGreetingTarget) {
                    const aiIcon = `<div class="synapse-chat__avatar"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 32 32"><use href="#gemini-icon" fill="url(#gemini-gradient)"></use></svg></div>`;
                    const msg = this.welcomeMessageValue || "Nouvelle conversation démarrée !";
                    this.messagesTarget.innerHTML = `
                        <div class="synapse-chat__message synapse-chat__message--assistant">
                             ${aiIcon}
                            <div class="synapse-chat__content">
                                <div class="synapse-chat__bubble"><p>${msg}</p></div>
                            </div>
                        </div>
                     `;
                }

                this.inputTarget.focus();
            } else {
                throw new Error(data.error || 'Reset failed');
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    }

    addMessage(text, role, debugData = null) {
        let formattedText = text;

        // DEBUG: Log original text
        if (role === 'assistant') {
            console.log('🔍 [DEBUG] Original text:', text);
            console.log('🔍 [DEBUG] Has <thinking>?', text.includes('<thinking>'));
        }

        // Extract and remove thinking blocks (for old data in database)
        if (role === 'assistant') {
            // Remove complete thinking blocks (with or without newlines)
            formattedText = formattedText.replace(/<thinking>[\s\S]*?<\/thinking>/gi, '');
            formattedText = formattedText.replace(/```thinking[\s\S]*?```/g, '');
            formattedText = formattedText.replace(/^\s*```\s*$/gm, '');

            // Remove orphan thinking tags (unclosed or malformed)
            formattedText = formattedText.replace(/<\/?thinking[^>]*>/gi, '');

            // Clean up multiple consecutive newlines
            formattedText = formattedText.replace(/\n{3,}/g, '\n\n');
            formattedText = formattedText.trim();

            // DEBUG: Log cleaned text
            console.log('🔍 [DEBUG] After cleaning:', formattedText);
            console.log('🔍 [DEBUG] Still has <thinking>?', formattedText.includes('<thinking>'));
        }

        // Simple markdown parsing
        formattedText = this.parseMarkdown(formattedText);

        // Debug info
        let debugHtml = '';
        if (this.isDebugMode && debugData && debugData.debug_id) {
            const debugUrl = `/synapse/_debug/${debugData.debug_id}`;
            debugHtml = `
                <button type="button" class="synapse-chat__debug-trigger"
                        onclick="window.open('${debugUrl}', 'SynapseDebug', 'width=1000,height=900')"
                        title="Debug">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                </button>
            `;
        }

        // Build avatar
        // Using generic classes so CSS/Theme handles the icon (SVG Symbol expected in DOM)
        const aiIcon = `<div class="synapse-chat__avatar"><div class="avatar-ai"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 32 32"><use href="#gemini-icon" fill="url(#gemini-gradient)"></use></svg></div></div>`;
        const userAvatar = `<div class="synapse-chat__avatar">👤</div>`;

        const avatarContent = role === 'user' ? userAvatar : aiIcon;

        let footerHtml = '';
        if (debugHtml) {
            footerHtml = `<div class="synapse-chat__footer">${debugHtml}</div>`;
        }

        const html = `
            <div class="synapse-chat__message synapse-chat__message--${role}">
                ${avatarContent}
                <div class="synapse-chat__content">
                    <div class="synapse-chat__bubble">${formattedText}</div>
                    ${footerHtml}
                </div>
            </div>
        `;

        this.messagesTarget.insertAdjacentHTML('beforeend', html);
        this.scrollToBottom();
    }

    async loadMarked() {
        try {
            const { parse } = await import('marked');
            this.markedParse = parse;
            // Rerender visible messages if needed, or just let new ones use it
            // Optional: this.rerenderMessages(); 
        } catch (e) {
            console.warn('Synapse: "marked" library not found. Install it for better Markdown rendering (php bin/console importmap:require marked). Using fallback parser.');
        }
    }

    parseMarkdown(text) {
        // Debug logging
        console.log('🔍 [Markdown Parser] Input:', text.substring(0, 100));

        if (this.markedParse) {
            try {
                const result = this.markedParse(text);
                console.log('✅ [Markdown Parser] Used marked library');
                return result;
            } catch (e) {
                console.error('❌ [Markdown Parser] Error with marked, using fallback:', e);
                // Fallback to regex if marked fails
            }
        } else {
            console.log('⚠️ [Markdown Parser] Using fallback (marked not loaded)');
        }

        // FALLBACK: Robust Regex Parser
        let html = text;

        // 1. PRIORITY: Convert Markdown links to styled buttons
        const linksBefore = (html.match(/\[([^\]]+)\]\(([^)]+)\)/g) || []).length;
        html = html.replace(
            /\[([^\]]+)\]\(([^)]+)\)/g,
            '<a href="$2" class="synapse-btn-action" target="_blank" rel="noopener noreferrer">$1</a>'
        );
        if (linksBefore > 0) {
            console.log(`🔗 [Markdown Parser] Converted ${linksBefore} link(s) to buttons`);
        }

        // 2. Text formatting
        html = html
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>');

        // 3. Code blocks
        html = html
            .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');

        // 4. Line breaks (LAST to avoid breaking HTML tags)
        html = html.replace(/\n/g, '<br>');

        // 5. Group consecutive action buttons into a flex container
        // Match 2+ consecutive buttons (with optional <br> between them)
        html = html.replace(
            /(<a class="synapse-btn-action"[^>]*>.*?<\/a>(?:<br>)?){2,}/g,
            (match) => {
                // Remove <br> tags between buttons and wrap in action group
                const cleanedButtons = match.replace(/<br>/g, '');
                const buttonCount = (cleanedButtons.match(/<a class="synapse-btn-action"/g) || []).length;
                console.log(`📦 [Markdown Parser] Grouped ${buttonCount} consecutive buttons`);
                return `<div class="synapse-action-group">${cleanedButtons}</div>`;
            }
        );

        console.log('✅ [Markdown Parser] Output:', html.substring(0, 100));
        return html;
    }

    setLoading(isLoading) {
        this.submitBtnTarget.disabled = isLoading;

        if (isLoading) {
            const aiIcon = `<div class="synapse-chat__avatar synapse-chat__avatar--loading"><div class="synapse-chat__spinner"></div><div class="avatar-ai"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 32 32"><use href="#gemini-icon" fill="url(#gemini-gradient)"></use></svg></div></div>`;

            this.messagesTarget.insertAdjacentHTML('beforeend', `
                <div class="synapse-chat__message synapse-chat__message--assistant synapse-chat__loading" id="synapse-loading">
                    ${aiIcon}
                    <div class="synapse-chat__content">
                        <span class="synapse-chat__typing-dots">Réflexion</span>
                    </div>
                </div>
            `);
            this.scrollToBottom();
        } else {
            const loading = this.element.querySelector('#synapse-loading');
            if (loading) loading.remove();
        }
    }

    scrollToBottom() {
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    updateUrlWithConversationId(conversationId) {
        const url = new URL(window.location.href);
        const currentConversationId = url.searchParams.get('conversation');

        // Only dispatch event if this is a NEW conversation (not already in URL)
        const isNewConversation = currentConversationId !== conversationId;

        url.searchParams.set('conversation', conversationId);

        // Update URL without reloading page
        window.history.pushState({}, '', url.toString());

        // Dispatch event for sidebar to refresh ONLY for new conversations
        if (isNewConversation) {
            document.dispatchEvent(new CustomEvent('assistant:conversation-created', {
                detail: { conversationId, title: 'Nouvelle conversation' }
            }));
        }
    }
}
