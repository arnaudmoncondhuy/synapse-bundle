import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller pour la sidebar de conversations
 *
 * Fonctionnalités :
 * - Affichage/masquage drawer
 * - Chargement conversations via API
 * - Suppression optimiste avec rollback
 * - Renommage inline (double-clic)
 * - Écoute events (conversation-created, title-updated)
 */
export default class extends Controller {
    static targets = ['list', 'toggle', 'drawer', 'empty'];
    static values = {
        apiUrl: String,
        currentConversationId: String
    };

    connect() {
        console.log('Synapse Sidebar Controller connected');
        this.loadConversations();
        this.setupEventListeners();
    }

    /**
     * Charge les conversations depuis l'API
     */
    async loadConversations() {
        try {
            const response = await fetch(this.apiUrlValue || '/synapse/api/conversations');
            if (!response.ok) throw new Error('Failed to load conversations');

            const conversations = await response.json();
            this.renderConversations(conversations);
        } catch (error) {
            console.error('Error loading conversations:', error);
            this.showError('Impossible de charger les conversations');
        }
    }

    /**
     * Affiche la liste des conversations
     */
    renderConversations(conversations) {
        if (conversations.length === 0) {
            this.showEmpty();
            return;
        }

        this.hideEmpty();

        this.listTarget.innerHTML = conversations.map(conv => `
            <div
                class="conversation-item ${conv.id === this.currentConversationIdValue ? 'active' : ''}"
                data-conversation-id="${conv.id}"
                data-action="click->sidebar#selectConversation dblclick->sidebar#startRename"
            >
                <div class="conversation-header">
                    <span class="conversation-title" data-sidebar-target="title">${this.escapeHtml(conv.title || 'Nouvelle conversation')}</span>
                    ${conv.risk_level !== 'NONE' ? `<span class="risk-badge risk-${conv.risk_level.toLowerCase()}">${this.getRiskEmoji(conv.risk_level)}</span>` : ''}
                </div>
                <div class="conversation-meta">
                    <span class="conversation-date">${this.formatDate(conv.updated_at)}</span>
                    <span class="conversation-count">${conv.message_count} msg</span>
                </div>
                <button
                    class="conversation-delete"
                    data-action="click->sidebar#deleteConversation:stop"
                    title="Supprimer"
                >
                    ×
                </button>
            </div>
        `).join('');
    }

    /**
     * Affiche le message vide
     */
    showEmpty() {
        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.remove('hidden');
        }
        if (this.hasListTarget) {
            this.listTarget.innerHTML = '';
        }
    }

    /**
     * Cache le message vide
     */
    hideEmpty() {
        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.add('hidden');
        }
    }

    /**
     * Toggle drawer (afficher/masquer)
     */
    toggle() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.toggle('open');
        }
    }

    /**
     * Ouvre le drawer
     */
    open() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.add('open');
        }
    }

    /**
     * Ferme le drawer
     */
    close() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.remove('open');
        }
    }

    /**
     * Sélectionne une conversation
     */
    selectConversation(event) {
        const conversationId = event.currentTarget.dataset.conversationId;

        // Désélectionner toutes
        this.listTarget.querySelectorAll('.conversation-item').forEach(item => {
            item.classList.remove('active');
        });

        // Sélectionner la courante
        event.currentTarget.classList.add('active');
        this.currentConversationIdValue = conversationId;

        // Dispatch event pour le chat
        this.dispatch('conversation-selected', { detail: { conversationId } });

        // Fermer drawer sur mobile
        if (window.innerWidth < 768) {
            this.close();
        }
    }

    /**
     * Supprime une conversation (optimistic UI)
     */
    async deleteConversation(event) {
        const item = event.currentTarget.closest('.conversation-item');
        const conversationId = item.dataset.conversationId;

        if (!confirm('Supprimer cette conversation ?')) {
            return;
        }

        // Optimistic UI : masquer immédiatement
        const itemHtml = item.outerHTML;
        item.style.opacity = '0.5';
        item.style.pointerEvents = 'none';

        try {
            const response = await fetch(`${this.apiUrlValue || '/synapse/api/conversations'}/${conversationId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) throw new Error('Delete failed');

            // Supprimer l'élément
            item.remove();

            // Vérifier si la liste est vide
            if (this.listTarget.children.length === 0) {
                this.showEmpty();
            }

            // Dispatch event
            this.dispatch('conversation-deleted', { detail: { conversationId } });

            // Si c'était la conversation active, dispatch event pour reset
            if (conversationId === this.currentConversationIdValue) {
                this.currentConversationIdValue = '';
                this.dispatch('conversation-reset');
            }
        } catch (error) {
            console.error('Error deleting conversation:', error);

            // Rollback : restaurer l'élément
            item.style.opacity = '1';
            item.style.pointerEvents = 'auto';

            this.showError('Impossible de supprimer la conversation');
        }
    }

    /**
     * Démarre le renommage inline (double-clic)
     */
    startRename(event) {
        const item = event.currentTarget;
        const titleSpan = item.querySelector('.conversation-title');
        const currentTitle = titleSpan.textContent;

        // Créer input
        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentTitle;
        input.className = 'conversation-title-input';

        // Remplacer le span par l'input
        titleSpan.replaceWith(input);
        input.focus();
        input.select();

        // Sauvegarder au blur ou Enter
        const save = async () => {
            const newTitle = input.value.trim();

            if (newTitle && newTitle !== currentTitle) {
                await this.renameConversation(item.dataset.conversationId, newTitle);
            }

            // Restaurer le span
            const span = document.createElement('span');
            span.className = 'conversation-title';
            span.textContent = newTitle || currentTitle;
            span.dataset.sidebarTarget = 'title';
            input.replaceWith(span);
        };

        input.addEventListener('blur', save);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            } else if (e.key === 'Escape') {
                input.value = currentTitle;
                input.blur();
            }
        });
    }

    /**
     * Renomme une conversation via l'API
     */
    async renameConversation(conversationId, newTitle) {
        try {
            const response = await fetch(`${this.apiUrlValue || '/synapse/api/conversations'}/${conversationId}/rename`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ title: newTitle })
            });

            if (!response.ok) throw new Error('Rename failed');

            // Dispatch event
            this.dispatch('conversation-renamed', {
                detail: { conversationId, title: newTitle }
            });
        } catch (error) {
            console.error('Error renaming conversation:', error);
            this.showError('Impossible de renommer la conversation');
        }
    }

    /**
     * Configure les event listeners
     */
    setupEventListeners() {
        // Écouter les events du chat
        document.addEventListener('assistant:conversation-created', (event) => {
            this.handleConversationCreated(event.detail);
        });

        document.addEventListener('assistant:title-updated', (event) => {
            this.handleTitleUpdated(event.detail);
        });

        // Responsive : fermer drawer au clic sur overlay
        if (this.hasDrawerTarget) {
            const overlay = this.drawerTarget.querySelector('.drawer-overlay');
            if (overlay) {
                overlay.addEventListener('click', () => this.close());
            }
        }
    }

    /**
     * Gère la création d'une nouvelle conversation
     */
    handleConversationCreated({ conversationId, title }) {
        this.currentConversationIdValue = conversationId;
        this.loadConversations(); // Recharger la liste
        this.open(); // Ouvrir la sidebar
    }

    /**
     * Gère la mise à jour d'un titre
     */
    handleTitleUpdated({ conversationId, title }) {
        const item = this.listTarget.querySelector(`[data-conversation-id="${conversationId}"]`);
        if (item) {
            const titleSpan = item.querySelector('.conversation-title');
            if (titleSpan) {
                titleSpan.textContent = title;
            }
        }
    }

    /**
     * Affiche une erreur (toast)
     */
    showError(message) {
        // TODO: Intégrer avec votre système de notifications
        console.error(message);
        alert(message);
    }

    /**
     * Formate une date
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));

        if (days === 0) {
            return 'Aujourd\'hui';
        } else if (days === 1) {
            return 'Hier';
        } else if (days < 7) {
            return `Il y a ${days}j`;
        } else {
            return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
        }
    }

    /**
     * Retourne l'emoji d'un niveau de risque
     */
    getRiskEmoji(level) {
        const emojis = {
            'WARNING': '⚠️',
            'CRITICAL': '🚨'
        };
        return emojis[level] || '';
    }

    /**
     * Échappe le HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Crée une nouvelle conversation (recharge la page sans conversation ID)
     */
    newConversation(event) {
        console.log('newConversation() appelée');
        event.preventDefault();

        // Supprimer le paramètre conversation de l'URL
        const url = new URL(window.location.href);
        console.log('URL actuelle:', url.toString());
        url.searchParams.delete('conversation');
        const newUrl = url.toString();
        console.log('Nouvelle URL:', newUrl);
        window.location.href = newUrl;
    }
}
