import { Controller } from '@hotwired/stimulus';

/**
 * Synapse Chat Controller
 *
 * Handles the chat UI: sending messages, receiving streaming responses,
 * rendering markdown, and displaying thinking/debug blocks.
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'submitBtn'];
    static values = { history: Array };

    connect() {
        this.scrollToBottom();
        this.inputTarget.focus();

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

        try {
            const response = await fetch('/synapse/api/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    debug: true
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
                            this.addMessage(event.payload.answer, 'assistant', event.payload.debug);
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
            // Robust pattern from Prisma to handle potential markdown wrapping
            const thinkingPattern = /(?:```thinking>|<thinking[\s\S]*?>)([\s\S]*?)<\/thinking>(?:```)?/gi;
            let match;

            while ((match = formattedText.match(thinkingPattern)) !== null) {
                const thinkingContent = match[1] || '';

                thinkingHtml += `
                    <details class="synapse-chat__thinking">
                        <summary>🧠 Analyse & Raisonnement</summary>
                        <div class="synapse-chat__thinking-content">${this.escapeHtml(thinkingContent.trim())}</div>
                    </details>
                `;

                formattedText = formattedText.replace(match[0], '');
            }
        }

        // 2. Clean residual tags
        formattedText = formattedText
            .replace(/<\/?thinking>/gi, '')
            .trim();

        // 3. Simple markdown parsing (basic)
        formattedText = this.parseMarkdown(formattedText);

        // 4. Debug info
        let debugHtml = '';
        if (debugData && debugData.turns) {
            const turnsInfo = debugData.turns.map((turn, index) => {
                let badge = '';
                if (turn.function_calls_count > 0) {
                    badge = `<span style="background:#3b82f6;color:white;padding:2px 6px;border-radius:4px;font-size:0.75em;">Appel Outil</span>`;
                } else if (index === debugData.turns.length - 1) {
                    badge = `<span style="background:#22c55e;color:white;padding:2px 6px;border-radius:4px;font-size:0.75em;">Réponse Finale</span>`;
                }

                return `
                    <div style="margin-bottom:8px;padding-left:8px;border-left:2px solid #4b5563;">
                        <strong>Tour ${turn.turn}</strong> ${badge}
                        <div style="color:#9ca3af;font-size:0.85em;">
                            ${turn.has_text ? `Texte : ${turn.text_length} car` : 'Aucun texte'}
                            ${turn.function_calls_count > 0 ? ` | Outils : ${turn.function_names.join(', ')}` : ''}
                        </div>
                    </div>
                `;
            }).join('');

            debugHtml = `
                <details class="synapse-chat__debug">
                    <summary>🛠️ Info Debug (${debugData.total_turns} tours)</summary>
                    <div class="synapse-chat__debug-content">${turnsInfo}</div>
                </details>
            `;
        }

        // 5. Build HTML
        const avatarContent = role === 'user' ? '👤' : '🤖';
        const html = `
            <div class="synapse-chat__message synapse-chat__message--${role}">
                <div class="synapse-chat__avatar">${avatarContent}</div>
                <div class="synapse-chat__content">
                    ${debugHtml}
                    ${thinkingHtml}
                    <div>${formattedText}</div>
                </div>
            </div>
        `;

        this.messagesTarget.insertAdjacentHTML('beforeend', html);
        this.scrollToBottom();
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
