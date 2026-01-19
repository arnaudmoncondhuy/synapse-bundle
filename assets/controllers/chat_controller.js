import { Controller } from '@hotwired/stimulus';

/**
 * Synapse Chat Controller
 *
 * Handles the chat UI: sending messages, receiving streaming responses,
 * rendering markdown, and displaying thinking/debug blocks.
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submitBtn', 'debug', 'personaSelect'];
    static values = {
        history: Array,
        debug: { type: Boolean, default: false }
    };

    connect() {
        this.scrollToBottom();
        this.inputTarget.focus();

        // Check for debug mode in URL
        const urlParams = new URLSearchParams(window.location.search);
        this.isDebugMode = urlParams.has('debug') || this.debugValue; // Keep value support just in case

        if (this.isDebugMode) {
            this.element.classList.add('synapse-chat--debug-mode');
        }

        // Restore history if present
        if (this.historyValue && this.historyValue.length > 0) {
            this.loadHistory(this.historyValue);
        }
    }

    loadHistory(history) {
        // Clear default welcome message if we have history
        const welcomeMsg = this.messagesTarget.querySelector('.synapse-chat__message--assistant');
        if (history.length > 0 && welcomeMsg) {
            welcomeMsg.remove();
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

        this.addMessage(message, 'user');
        this.inputTarget.value = '';
        this.inputTarget.style.height = 'auto';
        this.setLoading(true);

        // Determine debug mode
        const debugMode = this.isDebugMode;

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
                    debug: debugMode
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
                buffer = lines.pop(); // Keep incomplete line in buffer

                for (const line of lines) {
                    if (!line.trim()) continue;

                    try {
                        const event = JSON.parse(line);

                        if (event.type === 'status') {
                            this.updateLoadingStatus(event.payload.message);
                        } else if (event.type === 'result') {
                            this.setLoading(false);
                            this.addMessage(event.payload.answer, 'assistant', event.payload);
                        } else if (event.type === 'error') {
                            throw new Error(event.payload);
                        }
                    } catch (e) {
                        console.error('Error parsing stream:', e);
                    }
                }
            }

        } catch (error) {
            this.setLoading(false);
            this.addMessage('Désolé, une erreur est survenue : ' + error.message, 'assistant');
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
                this.messagesTarget.innerHTML = `
                    <div class="synapse-chat__message synapse-chat__message--assistant">
                        <div class="synapse-chat__avatar">🤖</div>
                        <div class="synapse-chat__content">
                            <p>Nouvelle conversation démarrée ! Comment puis-je vous aider ?</p>
                        </div>
                    </div>
                `;
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

        // 1. Extract thinking blocks
        let thinkingHtml = '';
        if (role === 'assistant') {
            // Extraction plus robuste via parsing manuel pour éviter les pièges des Regex
            const result = this.extractThinking(formattedText);
            thinkingHtml = result.html;
            formattedText = result.remainingText;
        }

        // 2. Clean residual tags (sécurité)
        formattedText = formattedText
            .replace(/<\/?thinking>/gi, '')
            .trim();

        // 3. Simple markdown parsing (basic)
        formattedText = this.parseMarkdown(formattedText);

        // 4. Debug info
        // 4. Debug info (Popup Button)
        let debugHtml = '';
        // 4. Debug info (Server-Side Link)
        if (this.isDebugMode && debugData && debugData.debug_id) {
            const svgWrench = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>`;

            const debugUrl = `/synapse/_debug/${debugData.debug_id}`;

            debugHtml = `
                <button type="button" class="synapse-chat__debug-trigger" 
                        onclick="window.open('${debugUrl}', 'SynapseDebug', 'width=1000,height=900')" 
                        title="Ouvrir Debug Serveur">
                    ${svgWrench}
                </button>
            `;
        }

        // 5. Build HTML (Footer structure)
        const avatarContent = role === 'user' ? '👤' : '🤖';

        let footerHtml = '';
        if (thinkingHtml || debugHtml) {
            footerHtml = `<div class="synapse-chat__footer">${thinkingHtml}${debugHtml}</div>`;
        }

        const html = `
            <div class="synapse-chat__message synapse-chat__message--${role}">
                <div class="synapse-chat__avatar">${avatarContent}</div>
                <div class="synapse-chat__content">
                    <div class="synapse-chat__bubble">${formattedText}</div>
                    ${footerHtml}
                </div>
            </div>
        `;

        this.messagesTarget.insertAdjacentHTML('beforeend', html);
        this.scrollToBottom();
    }

    extractThinking(text) {
        // En mode Server-Side Debug, on ne veut plus afficher l'étincelle dans le chat.
        // On se contente de supprimer les balises <thinking> du texte affiché.
        // Le contenu de la pensée est préservé côté serveur et visible via le bouton debug.

        let remainingText = text;

        // 1. Supprimer les blocs <thinking> standard
        remainingText = remainingText.replace(/<thinking>[\s\S]*?<\/thinking>/g, '');

        // 2. Supprimer les blocs markdown ```thinking
        remainingText = remainingText.replace(/```thinking[\s\S]*?```/g, '');

        // 3. Supprimer d'éventuels backticks orphelins (souvent laissés par un parsing partiel)
        // On supprime les lignes qui ne contiennent que des backticks ```
        remainingText = remainingText.replace(/^\s*```\s*$/gm, '');

        // 4. Nettoyage final des espaces multiples
        remainingText = remainingText.trim();

        return { html: '', remainingText }; // HTML vide = pas d'étincelle
    }

    parseMarkdown(text) {
        // Very basic markdown parsing (for full support, use marked.js)
        return text
            // Bold
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            // Code blocks
            .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
            // Inline code
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            // Line breaks
            .replace(/\n/g, '<br>');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    setLoading(isLoading) {
        this.submitBtnTarget.disabled = isLoading;

        if (isLoading) {
            this.messagesTarget.insertAdjacentHTML('beforeend', `
                <div class="synapse-chat__message synapse-chat__message--assistant synapse-chat__loading" id="synapse-loading">
                    <div class="synapse-chat__avatar">🤖</div>
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
}
