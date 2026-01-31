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
        this.inputTarget.focus();

        // Check for debug mode in URL
        const urlParams = new URLSearchParams(window.location.search);
        this.isDebugMode = urlParams.has('debug') || this.debugValue;

        if (this.isDebugMode) {
            this.element.classList.add('synapse-chat--debug-mode');
        }

        // Restore history if present
        if (this.historyValue && this.historyValue.length > 0) {
            this.loadHistory(this.historyValue);
        }
    }

    loadHistory(history) {
        // Clear default greeting if we have history
        // Use class-based toggling for welcome mode
        if (history.length > 0) {
            if (this.hasContainerTarget) {
                this.containerTarget.classList.remove('mode-welcome');
                this.containerTarget.classList.add('mode-chat');
            }
            if (this.hasGreetingTarget) {
                this.greetingTarget.classList.add('hidden');
            }
        }

        history.forEach(msg => {
            const part = msg.parts[0];
            if (msg.role === 'user' && part.text) {
                this.addMessage(part.text, 'user');
            } else if (msg.role === 'model' && part.text) {
                this.addMessage(part.text, 'assistant', msg.metadata?.debug);
            }
        });

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

        try {
            const response = await fetch('/synapse/api/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    options: { persona: persona },
                    debug: this.isDebugMode
                })
            });

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();

                for (const line of lines) {
                    if (!line.trim()) continue;

                    try {
                        const evt = JSON.parse(line);

                        if (evt.type === 'status') {
                            this.updateLoadingStatus(evt.payload.message);
                        } else if (evt.type === 'result') {
                            this.setLoading(false);
                            this.addMessage(evt.payload.answer, 'assistant', evt.payload);

                            // Update URL with conversation ID if present
                            if (evt.payload.conversation_id) {
                                this.updateUrlWithConversationId(evt.payload.conversation_id);
                            }
                        } else if (evt.type === 'error') {
                            throw new Error(evt.payload);
                        }
                    } catch (e) {
                        console.error('Synapse Stream Error:', e);
                    }
                }
            }

        } catch (error) {
            this.setLoading(false);
            this.addMessage('Error: ' + error.message, 'assistant');
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

        // Extract and remove thinking blocks
        if (role === 'assistant') {
            formattedText = formattedText.replace(/<thinking>[\s\S]*?<\/thinking>/g, '');
            formattedText = formattedText.replace(/```thinking[\s\S]*?```/g, '');
            formattedText = formattedText.replace(/^\s*```\s*$/gm, '');
            formattedText = formattedText.trim();
        }

        // Clean residual tags
        formattedText = formattedText.replace(/<\/?thinking>/gi, '').trim();

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

    parseMarkdown(text) {
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
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
        url.searchParams.set('conversation', conversationId);

        // Update URL without reloading page
        window.history.pushState({}, '', url.toString());

        // Dispatch event for sidebar to refresh
        document.dispatchEvent(new CustomEvent('assistant:conversation-created', {
            detail: { conversationId, title: 'Nouvelle conversation' }
        }));
    }
}
