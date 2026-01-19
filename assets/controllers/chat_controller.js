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
        if (this.isDebugMode && debugData) {
            // Store debug data in a way accessible to the click handler
            // Since we're delivering HTML string, we'll use a data attribute with encoded JSON
            // BEWARE: Large JSON in data attributes can be heavy, but functional for this scale.
            const debugJson = JSON.stringify(debugData).replace(/"/g, '&quot;');

            const svgWrench = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>`;

            debugHtml = `
                <button type="button" class="synapse-chat__debug-trigger" 
                        onclick="window.synapseOpenDebug(this)" 
                        data-debug="${debugJson}"
                        title="Ouvrir Debug">
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
        let html = '';
        let remainingText = text;
        const markers = [
            { start: '<thinking>', end: '</thinking>' },
            { start: '```thinking', end: '```' } // Ordre important : checking plus spécifique d'abord si besoin
        ];

        // On boucle tant qu'on trouve des marqueurs
        let found = true;
        while (found) {
            found = false;
            let bestMatch = null;

            // Trouver le premier marqueur
            for (const marker of markers) {
                const startIndex = remainingText.indexOf(marker.start);
                if (startIndex !== -1) {
                    if (!bestMatch || startIndex < bestMatch.index) {
                        bestMatch = { index: startIndex, marker: marker };
                    }
                }
            }

            if (bestMatch) {
                found = true;
                const { index, marker } = bestMatch;
                const contentStart = index + marker.start.length;
                let contentEnd = remainingText.indexOf(marker.end, contentStart);

                // Cas spécifique Markdown : on ne veut pas s'arrêter sur un ``` imbriqué si possible, 
                // mais c'est dur à deviner. Pour l'instant, on prend le premier ``` fermant trouvé
                // SAUF si c'est immédiatement collé (cas vide)

                let extractedContent = '';
                let fullMatchLength = 0;

                if (contentEnd === -1) {
                    // Pas de fin trouvée : on prend tout le reste (cas de flux coupé ou oubli IA)
                    extractedContent = remainingText.substring(contentStart);
                    remainingText = remainingText.substring(0, index); // On enlève le bloc du texte principal
                } else {
                    extractedContent = remainingText.substring(contentStart, contentEnd);
                    const matchEnd = contentEnd + marker.end.length;

                    // Reconstruction du texte sans le bloc de pensée
                    remainingText = remainingText.substring(0, index) + remainingText.substring(matchEnd);
                }

                if (extractedContent.trim().length > 0) {
                    const svgSparkles = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275Z"/></svg>`;

                    html += `
                        <details class="synapse-chat__thinking">
                            <summary title="Raisonnement">${svgSparkles}</summary>
                            <div class="synapse-chat__thinking-content">${this.escapeHtml(extractedContent.trim())}</div>
                        </details>
                    `;
                }
            }
        }

        return { html, remainingText };
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

// Global helper for opening debug popup (to avoid rigorous event binding on dynamic HTML)
window.synapseOpenDebug = function (btn) {
    const data = JSON.parse(btn.getAttribute('data-debug'));

    const win = window.open('', 'SynapseDebug', 'width=800,height=900');
    if (!win) return;

    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Synapse Debug Details</title>
            <style>
                body { font-family: system-ui, -apple-system, sans-serif; padding: 20px; background: #f9fafb; color: #111827; }
                h1 { border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
                h2 { margin-top: 30px; color: #374151; display: flex; align-items: center; gap: 8px; }
                pre { background: #1f2937; color: #f3f4f6; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 14px; }
                .badge { background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 500; }
                .turn { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 15px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
                .turn-header { font-weight: 600; margin-bottom: 10px; display: flex; justify-content: space-between; }
                .thinking { background: #fffbeb; border-left: 4px solid #fcd34d; padding: 10px; margin: 10px 0; color: #92400e; font-style: italic; }
            </style>
        </head>
        <body>
            <h1>🤖 Synapse Debug Infos</h1>
            
            <section>
                <h2>📜 Prompt Système</h2>
                <pre>${data.system_prompt || 'Non disponible'}</pre>
            </section>

            <section>
                <h2>🔄 Historique de conversation</h2>
                <pre>${JSON.stringify(data.history || [], null, 2)}</pre>
            </section>

            <section>
                <h2>⚡ Cycles de réflexion (Turns)</h2>
                ${(data.turns || []).map(t => `
                    <div class="turn">
                        <div class="turn-header">
                            <span>Tour ${t.turn}</span>
                            <span>${t.function_calls_count > 0 ? '<span class="badge">Outils</span>' : ''}</span>
                        </div>
                        <div>Arguments: <pre>${JSON.stringify(t.args || {}, null, 2)}</pre></div>
                        <div>Résultat partiel: ${t.text_content ? t.text_content.substring(0, 100) + '...' : '(vide)'}</div>
                    </div>
                `).join('')}
            </section>

            <section>
                <h2>📡 Réponse API Brute</h2>
                <pre>${JSON.stringify(data.raw_response || {}, null, 2)}</pre>
            </section>
        </body>
        </html>
    `;

    win.document.write(html);
    win.document.close();
};
