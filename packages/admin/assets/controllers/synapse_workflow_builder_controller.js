import { Controller } from '@hotwired/stimulus';

/**
 * Synapse Workflow Builder Controller — Chantier J (2026-04-11).
 *
 * Remplace l'IIFE inline de l'ancien `workflow_edit.html.twig` par un
 * controller Stimulus propre qui gère la création/édition/suppression des
 * 5 types de steps supportés par le moteur WorkflowEngine :
 *
 *   - `agent`        : appelle un agent LLM nommé (type historique)
 *   - `conditional`  : évalue une expression JSONPath, produit un flag
 *   - `parallel`     : orchestre N branches indépendantes
 *   - `loop`         : itère un step template sur un array
 *   - `sub_workflow` : délègue à un workflow persistant existant
 *
 * Le controller fait la sérialisation vers l'input hidden `definition`
 * à chaque mutation. Le POST vers `workflow_edit` récupère le JSON et
 * le passe à `WorkflowDefinitionValidator` côté PHP.
 *
 * ## Values attendues (injectées via data-attributes)
 *
 * - `agents`            : array des agents disponibles [{ key, name, emoji }, ...]
 * - `workflows`         : array des workflows actifs pour sub_workflow [{ key, name }, ...]
 * - `initialDefinition` : le JSON initial (hidden input value, dupliqué en value pour bootstrap fiable)
 *
 * ## Targets
 *
 * - `stepsContainer`   : div qui reçoit les cards de steps
 * - `outputsContainer` : div qui reçoit les rows d'outputs
 * - `definitionInput`  : input hidden name="definition" (source of truth)
 * - `jsonTextarea`     : textarea du mode JSON
 * - `visualPanel`      : panel visual builder
 * - `jsonPanel`        : panel JSON editor
 * - `addStepButton`    : bouton + type picker dropdown
 * - `addOutputButton`  : bouton pour ajouter un output
 * - `modeVisualButton` : bouton toggle mode visual
 * - `modeJsonButton`   : bouton toggle mode JSON
 *
 * ## Scope Chantier J partie 1
 *
 * Cette version **ne supporte encore que le type `agent`** (port direct
 * de l'ancien IIFE). Les 4 autres types sont ajoutés en partie 2. Elle
 * livre néanmoins toute l'infra (controller Stimulus, data-attrs,
 * targets, registration) pour que partie 2 soit purement additive.
 *
 * Les steps de type non-agent déjà présents en base sont affichés en
 * mode read-only avec un badge + preview (comportement dégradé équivalent
 * à ce que faisait le patch `a0db3a8` sur l'IIFE).
 */

const STEP_TYPES = [
    { value: 'agent', label: 'Agent IA', icon: '🤖' },
    { value: 'conditional', label: 'Condition', icon: '🔀' },
    { value: 'parallel', label: 'Parallèle', icon: '⫘' },
    { value: 'loop', label: 'Boucle', icon: '🔁' },
    { value: 'sub_workflow', label: 'Sous-workflow', icon: '📦' },
];

const MAX_NESTING_DEPTH = 3;

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

export default class extends Controller {
    static targets = [
        'stepsContainer',
        'outputsContainer',
        'definitionInput',
        'jsonTextarea',
        'visualPanel',
        'jsonPanel',
        'addStepButton',
        'addOutputButton',
        'modeVisualButton',
        'modeJsonButton',
    ];

    static values = {
        agents: { type: Array, default: [] },
        workflows: { type: Array, default: [] },
    };

    // ── Lifecycle ───────────────────────────────────────────────────────────

    connect() {
        // Parse initial definition from hidden input
        try {
            this.def = JSON.parse(this.definitionInputTarget.value);
        } catch (e) {
            this.def = { version: 1, steps: [] };
        }
        if (!this.def.steps) this.def.steps = [];
        if (!this.def.outputs) this.def.outputs = {};
        // Le message du chat est toujours disponible comme input implicite
        if (!this.def.inputs || Object.keys(this.def.inputs).length === 0) {
            this.def.inputs = { message: { type: 'string', required: true } };
        }

        this.render();
    }

    // ── Public actions (invoked via data-action) ────────────────────────────

    addStep(event) {
        // Chantier J partie 2 : ouvre un dropdown de type à côté du bouton
        // pour que le user choisisse le type avant l'insertion. Click
        // ailleurs = fermeture.
        this._closeOpenTypeDropdown();

        const dropdown = document.createElement('div');
        dropdown.className = 'synapse-wf-step__add-dropdown';
        for (const t of STEP_TYPES) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'synapse-wf-step__add-option synapse-wf-step__add-option--' + t.value;
            btn.innerHTML = '<span class="synapse-wf-step__add-option-icon">' + t.icon + '</span><span>' + t.label + '</span>';
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.def.steps.push(this._createStepOfType(t.value));
                this._syncJson();
                this._closeOpenTypeDropdown();
                this.render();
            });
            dropdown.appendChild(btn);
        }

        // Positionne au-dessous du bouton cliqué
        const btn = event?.currentTarget || this.addStepButtonTarget;
        btn.parentNode.insertBefore(dropdown, btn.nextSibling);
        this._openDropdown = dropdown;

        // Fermeture au click ailleurs
        setTimeout(() => {
            const onClickOutside = (e) => {
                if (!dropdown.contains(e.target) && e.target !== btn) {
                    this._closeOpenTypeDropdown();
                    document.removeEventListener('click', onClickOutside);
                }
            };
            document.addEventListener('click', onClickOutside);
        }, 0);
    }

    _closeOpenTypeDropdown() {
        if (this._openDropdown) {
            this._openDropdown.remove();
            this._openDropdown = null;
        }
    }

    addOutput() {
        if (!this.def.outputs) this.def.outputs = {};
        this.def.outputs['output_' + (Object.keys(this.def.outputs).length + 1)] = '';
        this._syncJson();
        this.render();
    }

    setModeVisual() {
        // Parse textarea back into def
        try {
            const parsed = JSON.parse(this.jsonTextareaTarget.value);
            Object.assign(this.def, parsed);
            this.def.steps = parsed.steps || [];
            this.def.outputs = parsed.outputs || {};
        } catch (e) {
            alert('JSON invalide : ' + e.message);
            return;
        }
        this.visualPanelTarget.hidden = false;
        this.jsonPanelTarget.hidden = true;
        this._setActiveButton(this.modeVisualButtonTarget, this.modeJsonButtonTarget);
        this.render();
    }

    setModeJson() {
        this._syncJson();
        this.jsonTextareaTarget.value = JSON.stringify(this.def, null, 2);
        this.visualPanelTarget.hidden = true;
        this.jsonPanelTarget.hidden = false;
        this._setActiveButton(this.modeJsonButtonTarget, this.modeVisualButtonTarget);
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    render() {
        this.stepsContainerTarget.innerHTML = '';

        if (this.def.steps.length === 0) {
            this.stepsContainerTarget.innerHTML =
                '<div class="synapse-admin-alert synapse-admin-alert--info">' +
                '<i data-lucide="info"></i>' +
                '<div>Aucun step défini. Clique sur « Ajouter un step » pour commencer.</div>' +
                '</div>';
            this._renderOutputs();
            this._syncJson();
            this._refreshIcons();
            return;
        }

        for (let i = 0; i < this.def.steps.length; i++) {
            const card = this._renderStep(this.def.steps[i], {
                depth: 0,
                index: i,
                allowMove: true,
                allowRemove: true,
                isFirst: i === 0,
                isLast: i === this.def.steps.length - 1,
                onUpdate: () => {
                    this._syncJson();
                    this.render();
                },
                onMoveUp: () => this._moveStep(i, -1),
                onMoveDown: () => this._moveStep(i, 1),
                onRemove: () => this._removeStep(i),
            });
            this.stepsContainerTarget.appendChild(card);
        }
        this._renderOutputs();
        this._syncJson();
        this._refreshIcons();
    }

    /**
     * Render a single step (dispatches on type).
     *
     * Recursive: called from _renderParallelBody / _renderLoopBody for nested
     * branches and templates (partie 2).
     *
     * Options shape:
     *   {
     *     depth: number,              // 0 = top-level, increments per nesting
     *     index: number|null,         // position in parent array (for move/remove)
     *     allowMove: boolean,         // show up/down buttons
     *     allowRemove: boolean,       // show remove button
     *     isFirst: boolean,           // disable move-up
     *     isLast: boolean,            // disable move-down
     *     onUpdate: () => void,       // called after any mutation
     *     onMoveUp: () => void,
     *     onMoveDown: () => void,
     *     onRemove: () => void,
     *   }
     */
    _renderStep(step, options) {
        const stepType = step.type || 'agent';
        const typeMeta = STEP_TYPES.find((t) => t.value === stepType) || STEP_TYPES[0];

        const card = document.createElement('div');
        card.className =
            'synapse-admin-card synapse-wf-step synapse-wf-step--' + stepType
            + ' synapse-wf-step--depth-' + options.depth
            + ' synapse-admin-mb-md';
        card.dataset.stepType = stepType;

        // ── Header
        card.appendChild(this._buildStepHeader(step, typeMeta, options));

        // ── Body
        const body = document.createElement('div');
        body.className = 'synapse-admin-card__body synapse-admin-card__body--compact synapse-wf-step__body';

        switch (stepType) {
            case 'agent':
                this._renderAgentBody(step, body, options);
                break;
            case 'conditional':
                this._renderConditionalBody(step, body, options);
                break;
            case 'parallel':
                this._renderParallelBody(step, body, options);
                break;
            case 'loop':
                this._renderLoopBody(step, body, options);
                break;
            case 'sub_workflow':
                this._renderSubWorkflowBody(step, body, options);
                break;
            default:
                this._renderReadOnlyBody(step, stepType, body);
                break;
        }

        card.appendChild(body);
        return card;
    }

    // ── Header (commun à tous les types) ────────────────────────────────────

    _buildStepHeader(step, typeMeta, options) {
        const header = document.createElement('div');
        header.className = 'synapse-admin-card__header synapse-wf-step__header';

        // Icon (emoji du type)
        const iconEl = document.createElement('span');
        iconEl.className = 'synapse-wf-step__icon';
        iconEl.textContent = typeMeta.icon;
        header.appendChild(iconEl);

        // Name input
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'synapse-admin-input synapse-wf-step__name-input';
        nameInput.value = step.name || '';
        nameInput.placeholder = 'nom du step (a-z0-9_-)';
        nameInput.addEventListener('input', (e) => {
            step.name = e.target.value.replace(/[^a-zA-Z0-9_-]/g, '');
            e.target.value = step.name;
            this._syncJson();
        });
        header.appendChild(nameInput);

        // Type picker — dropdown qui change le type du step. Au changement,
        // les champs du type courant sont effacés et ceux du nouveau type
        // sont initialisés via _createStepOfType.
        const typeSelect = document.createElement('select');
        typeSelect.className = 'synapse-admin-input synapse-wf-step__type-select synapse-wf-step__type-select--' + (step.type || 'agent');
        typeSelect.title = 'Type de step';
        for (const t of STEP_TYPES) {
            const opt = new Option(t.icon + ' ' + t.label, t.value);
            if ((step.type || 'agent') === t.value) {
                opt.selected = true;
            }
            typeSelect.appendChild(opt);
        }
        // Désactiver le type picker quand on dépasse la limite de nesting
        // (éviter que le user imbrique parallel > parallel > parallel...
        // plus profondément qu'autorisé, car le runtime borne via
        // AgentContext::maxDepth).
        if (options.depth >= MAX_NESTING_DEPTH - 1) {
            typeSelect.title = 'Type fixe à ce niveau de profondeur (max ' + MAX_NESTING_DEPTH + ')';
        }
        typeSelect.addEventListener('change', () => {
            const newType = typeSelect.value;
            const oldType = step.type || 'agent';
            if (newType === oldType) return;
            if (!this._confirmTypeChange(step, oldType, newType)) {
                typeSelect.value = oldType;
                return;
            }
            this._changeStepType(step, newType);
            options.onUpdate();
        });
        header.appendChild(typeSelect);

        // Actions
        const actions = document.createElement('div');
        actions.className = 'synapse-wf-step__actions';

        if (options.allowMove) {
            if (!options.isFirst) {
                const up = this._makeIconButton('chevron-up', 'Monter', () => options.onMoveUp());
                actions.appendChild(up);
            }
            if (!options.isLast) {
                const down = this._makeIconButton('chevron-down', 'Descendre', () => options.onMoveDown());
                actions.appendChild(down);
            }
        }
        if (options.allowRemove) {
            const remove = this._makeIconButton('trash-2', 'Supprimer', () => options.onRemove(), 'danger');
            actions.appendChild(remove);
        }

        header.appendChild(actions);
        return header;
    }

    _makeIconButton(lucideName, title, onClick, variant = 'ghost') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'synapse-admin-btn synapse-admin-btn--icon synapse-admin-btn--sm synapse-admin-btn--' + variant;
        btn.title = title;
        btn.innerHTML = '<i data-lucide="' + lucideName + '"></i>';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            onClick();
        });
        return btn;
    }

    // ── Body — Agent (type historique, seul supporté en partie 1) ───────────

    _renderAgentBody(step, body, options) {
        // Agent select
        const agentGroup = document.createElement('div');
        agentGroup.className = 'synapse-admin-form-group synapse-admin-mb-md';

        const agentLabel = document.createElement('label');
        agentLabel.className = 'synapse-admin-label';
        agentLabel.innerHTML = 'Agent <span class="synapse-admin-required">*</span>';
        agentGroup.appendChild(agentLabel);

        const agentSelect = document.createElement('select');
        agentSelect.className = 'synapse-admin-input';
        const placeholder = new Option('— Sélectionne un agent —', '');
        agentSelect.appendChild(placeholder);

        let agentFound = false;
        for (const a of this.agentsValue) {
            const opt = new Option(`${a.emoji || ''} ${a.name} (${a.key})`, a.key);
            if (step.agent_name === a.key) {
                opt.selected = true;
                agentFound = true;
            }
            agentSelect.appendChild(opt);
        }
        if (step.agent_name && !agentFound) {
            const ghost = new Option('⚠️ ' + step.agent_name + ' (introuvable)', step.agent_name);
            ghost.selected = true;
            agentSelect.appendChild(ghost);
        }
        agentSelect.addEventListener('change', () => {
            step.agent_name = agentSelect.value;
            this._syncJson();
        });
        agentGroup.appendChild(agentSelect);
        body.appendChild(agentGroup);

        // Input mapping section
        body.appendChild(this._buildMappingSection(step, options));
    }

    _buildMappingSection(step, options) {
        const section = document.createElement('div');
        section.className = 'synapse-wf-step__mapping synapse-admin-mt-sm';

        const mappingHeader = document.createElement('div');
        mappingHeader.className = 'synapse-admin-flex synapse-admin-items-center synapse-admin-justify-between synapse-admin-mb-sm';
        const label = document.createElement('span');
        label.className = 'synapse-admin-label synapse-admin-text-sm';
        label.textContent = 'Input mapping';
        mappingHeader.appendChild(label);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'synapse-admin-btn synapse-admin-btn--ghost synapse-admin-btn--sm';
        addBtn.innerHTML = '<i data-lucide="plus"></i> Ajouter un mapping';
        addBtn.addEventListener('click', () => {
            if (!step.input_mapping) step.input_mapping = {};
            step.input_mapping['param_' + Date.now()] = '';
            this._syncJson();
            options.onUpdate();
        });
        mappingHeader.appendChild(addBtn);
        section.appendChild(mappingHeader);

        const rows = document.createElement('div');
        rows.className = 'synapse-wf-step__mapping-rows';
        const mapping = step.input_mapping || {};
        const keys = Object.keys(mapping);
        if (keys.length === 0) {
            rows.innerHTML = '<div class="synapse-admin-text-xs synapse-admin-text-tertiary" style="padding:0.25rem 0;">Aucun mapping.</div>';
        } else {
            for (const k of keys) {
                rows.appendChild(this._renderMappingRow(step, k, options));
            }
        }
        section.appendChild(rows);
        return section;
    }

    _renderMappingRow(step, key, options) {
        const row = document.createElement('div');
        row.className = 'synapse-wf-step__mapping-row';

        const value = step.input_mapping[key];
        const isLiteral = value !== undefined && value !== null && !String(value).startsWith('$');

        row.innerHTML =
            '<input type="text" class="synapse-admin-input synapse-wf-step__mapping-key" value="' + escapeHtml(key) + '" placeholder="clef">' +
            '<select class="synapse-admin-input synapse-wf-step__mapping-source">' + this._sourceOptions(value) + '</select>' +
            '<button type="button" class="synapse-admin-btn synapse-admin-btn--icon synapse-admin-btn--ghost synapse-admin-btn--sm" data-action="remove-mapping"><i data-lucide="x"></i></button>';

        const selectEl = row.querySelector('.synapse-wf-step__mapping-source');
        const removeBtn = row.querySelector('[data-action="remove-mapping"]');
        const keyInput = row.querySelector('.synapse-wf-step__mapping-key');

        // Literal text input (inserted conditionally)
        const showLiteralInput = () => {
            let lit = row.querySelector('.synapse-wf-step__mapping-literal');
            if (lit) return lit;
            lit = document.createElement('input');
            lit.type = 'text';
            lit.className = 'synapse-admin-input synapse-wf-step__mapping-literal';
            lit.placeholder = 'texte fixe';
            const currentValue = step.input_mapping[key];
            lit.value = (currentValue !== undefined && currentValue !== null && !String(currentValue).startsWith('$')) ? currentValue : '';
            lit.addEventListener('input', () => {
                step.input_mapping[key] = lit.value;
                this._syncJson();
            });
            row.insertBefore(lit, removeBtn);
            return lit;
        };
        const hideLiteralInput = () => {
            const lit = row.querySelector('.synapse-wf-step__mapping-literal');
            if (lit) lit.remove();
        };

        if (isLiteral) showLiteralInput();

        // Key change
        let currentKey = key;
        keyInput.addEventListener('input', () => {
            const newKey = keyInput.value.replace(/[^a-zA-Z0-9_-]/g, '');
            keyInput.value = newKey;
            if (newKey !== currentKey && newKey) {
                const val = step.input_mapping[currentKey];
                delete step.input_mapping[currentKey];
                step.input_mapping[newKey] = val;
                currentKey = newKey;
                this._syncJson();
            }
        });

        // Source change
        selectEl.addEventListener('change', () => {
            if (selectEl.value === '__literal__') {
                step.input_mapping[currentKey] = '';
                const lit = showLiteralInput();
                lit.focus();
            } else {
                step.input_mapping[currentKey] = selectEl.value;
                hideLiteralInput();
            }
            this._syncJson();
        });

        // Remove
        removeBtn.addEventListener('click', () => {
            delete step.input_mapping[currentKey];
            this._syncJson();
            options.onUpdate();
        });

        return row;
    }

    // ── Body — Conditional ─────────────────────────────────────────────────

    _renderConditionalBody(step, body, options) {
        // Condition expression (JSONPath ou littéral)
        const condGroup = document.createElement('div');
        condGroup.className = 'synapse-admin-form-group synapse-admin-mb-md';
        const condLabel = document.createElement('label');
        condLabel.className = 'synapse-admin-label';
        condLabel.innerHTML = 'Condition (JSONPath) <span class="synapse-admin-required">*</span>';
        condGroup.appendChild(condLabel);

        const condInput = document.createElement('input');
        condInput.type = 'text';
        condInput.className = 'synapse-admin-input synapse-admin-font-mono';
        condInput.placeholder = '$.steps.classify.output.data.priority';
        condInput.value = step.condition || '';
        condInput.addEventListener('input', () => {
            step.condition = condInput.value;
            this._syncJson();
        });
        condGroup.appendChild(condInput);

        const condHelp = document.createElement('p');
        condHelp.className = 'synapse-admin-help-text';
        condHelp.innerHTML = 'Expression à évaluer. Commence par <code>$.</code> pour un JSONPath, sinon valeur littérale. Le résultat est accessible via <code>$.steps.' + escapeHtml(step.name || '&lt;nom&gt;') + '.output.data.matched</code>.';
        condGroup.appendChild(condHelp);
        body.appendChild(condGroup);

        // Toggle comparaison stricte vs truthy check
        const equalsGroup = document.createElement('div');
        equalsGroup.className = 'synapse-admin-form-group synapse-admin-mb-md';

        const equalsToggleLabel = document.createElement('label');
        equalsToggleLabel.className = 'synapse-admin-label synapse-admin-flex synapse-admin-items-center synapse-admin-gap-sm';
        const equalsCheckbox = document.createElement('input');
        equalsCheckbox.type = 'checkbox';
        equalsCheckbox.checked = 'equals' in step;
        equalsToggleLabel.appendChild(equalsCheckbox);
        const equalsLabelText = document.createElement('span');
        equalsLabelText.textContent = 'Comparaison stricte (sinon truthy check)';
        equalsToggleLabel.appendChild(equalsLabelText);
        equalsGroup.appendChild(equalsToggleLabel);

        const equalsInputWrap = document.createElement('div');
        equalsInputWrap.className = 'synapse-admin-mt-sm';
        equalsInputWrap.style.display = equalsCheckbox.checked ? '' : 'none';
        const equalsInput = document.createElement('input');
        equalsInput.type = 'text';
        equalsInput.className = 'synapse-admin-input synapse-admin-font-mono';
        equalsInput.placeholder = 'valeur à comparer (ex: "urgent" ou 42 ou true)';
        equalsInput.value = 'equals' in step ? JSON.stringify(step.equals) : '';
        equalsInput.addEventListener('input', () => {
            // On tente de parser comme JSON pour supporter string/number/bool/null
            try {
                step.equals = JSON.parse(equalsInput.value);
            } catch (e) {
                // Si c'est pas du JSON valide, on stocke comme string brute
                step.equals = equalsInput.value;
            }
            this._syncJson();
        });
        equalsInputWrap.appendChild(equalsInput);
        equalsGroup.appendChild(equalsInputWrap);

        equalsCheckbox.addEventListener('change', () => {
            if (equalsCheckbox.checked) {
                step.equals = step.equals ?? '';
                equalsInputWrap.style.display = '';
            } else {
                delete step.equals;
                equalsInputWrap.style.display = 'none';
            }
            this._syncJson();
        });
        body.appendChild(equalsGroup);
    }

    // ── Body — Parallel (récursif) ─────────────────────────────────────────

    _renderParallelBody(step, body, options) {
        if (!Array.isArray(step.branches)) {
            step.branches = [];
        }

        const header = document.createElement('div');
        header.className = 'synapse-admin-flex synapse-admin-items-center synapse-admin-justify-between synapse-admin-mb-sm';
        const label = document.createElement('span');
        label.className = 'synapse-admin-label';
        label.innerHTML = 'Branches <span class="synapse-admin-text-tertiary">(exécutées indépendamment)</span>';
        header.appendChild(label);

        const addBranchBtn = document.createElement('button');
        addBranchBtn.type = 'button';
        addBranchBtn.className = 'synapse-admin-btn synapse-admin-btn--ghost synapse-admin-btn--sm';
        addBranchBtn.innerHTML = '<i data-lucide="plus"></i> Ajouter une branche';
        addBranchBtn.addEventListener('click', () => {
            step.branches.push(this._createStepOfType('agent'));
            this._syncJson();
            options.onUpdate();
        });
        header.appendChild(addBranchBtn);
        body.appendChild(header);

        // Liste des branches — rendu récursif via _renderStep avec depth+1
        const branchesContainer = document.createElement('div');
        branchesContainer.className = 'synapse-wf-step__branches';

        if (step.branches.length === 0) {
            branchesContainer.innerHTML = '<div class="synapse-admin-text-xs synapse-admin-text-tertiary" style="padding:0.5rem;">Aucune branche. Clique « Ajouter une branche » pour commencer.</div>';
        } else {
            step.branches.forEach((branch, idx) => {
                const branchCard = this._renderStep(branch, {
                    depth: options.depth + 1,
                    index: idx,
                    allowMove: true,
                    allowRemove: true,
                    isFirst: idx === 0,
                    isLast: idx === step.branches.length - 1,
                    onUpdate: options.onUpdate,
                    onMoveUp: () => {
                        if (idx > 0) {
                            [step.branches[idx - 1], step.branches[idx]] = [step.branches[idx], step.branches[idx - 1]];
                            this._syncJson();
                            options.onUpdate();
                        }
                    },
                    onMoveDown: () => {
                        if (idx < step.branches.length - 1) {
                            [step.branches[idx + 1], step.branches[idx]] = [step.branches[idx], step.branches[idx + 1]];
                            this._syncJson();
                            options.onUpdate();
                        }
                    },
                    onRemove: () => {
                        step.branches.splice(idx, 1);
                        this._syncJson();
                        options.onUpdate();
                    },
                });
                branchesContainer.appendChild(branchCard);
            });
        }
        body.appendChild(branchesContainer);

        // Aide contextuelle
        const help = document.createElement('p');
        help.className = 'synapse-admin-help-text synapse-admin-mt-sm';
        help.innerHTML = 'Output accessible via <code>$.steps.' + escapeHtml(step.name || '&lt;nom&gt;') + '.output.data.branches.&lt;branchName&gt;.text</code>';
        body.appendChild(help);
    }

    // ── Body — Loop (récursif) ─────────────────────────────────────────────

    _renderLoopBody(step, body, options) {
        // items_path
        const itemsGroup = document.createElement('div');
        itemsGroup.className = 'synapse-admin-form-group synapse-admin-mb-md';
        const itemsLabel = document.createElement('label');
        itemsLabel.className = 'synapse-admin-label';
        itemsLabel.innerHTML = 'Items (JSONPath vers un array) <span class="synapse-admin-required">*</span>';
        itemsGroup.appendChild(itemsLabel);

        const itemsInput = document.createElement('input');
        itemsInput.type = 'text';
        itemsInput.className = 'synapse-admin-input synapse-admin-font-mono';
        itemsInput.placeholder = '$.inputs.documents';
        itemsInput.value = step.items_path || '';
        itemsInput.addEventListener('input', () => {
            step.items_path = itemsInput.value;
            this._syncJson();
        });
        itemsGroup.appendChild(itemsInput);
        body.appendChild(itemsGroup);

        // item_alias + max_iterations (ligne 2 colonnes)
        const row2 = document.createElement('div');
        row2.className = 'synapse-admin-grid synapse-admin-grid--2 synapse-admin-mb-md';

        const aliasGroup = document.createElement('div');
        aliasGroup.className = 'synapse-admin-form-group';
        const aliasLabel = document.createElement('label');
        aliasLabel.className = 'synapse-admin-label synapse-admin-text-sm';
        aliasLabel.textContent = 'Alias item';
        aliasGroup.appendChild(aliasLabel);
        const aliasInput = document.createElement('input');
        aliasInput.type = 'text';
        aliasInput.className = 'synapse-admin-input synapse-admin-font-mono';
        aliasInput.placeholder = 'item';
        aliasInput.value = step.item_alias || '';
        aliasInput.addEventListener('input', () => {
            step.item_alias = aliasInput.value || undefined;
            if (!step.item_alias) delete step.item_alias;
            this._syncJson();
        });
        aliasGroup.appendChild(aliasInput);
        row2.appendChild(aliasGroup);

        const maxGroup = document.createElement('div');
        maxGroup.className = 'synapse-admin-form-group';
        const maxLabel = document.createElement('label');
        maxLabel.className = 'synapse-admin-label synapse-admin-text-sm';
        maxLabel.textContent = 'Max itérations';
        maxGroup.appendChild(maxLabel);
        const maxInput = document.createElement('input');
        maxInput.type = 'number';
        maxInput.className = 'synapse-admin-input';
        maxInput.min = '1';
        maxInput.max = '1000';
        maxInput.placeholder = '50';
        maxInput.value = step.max_iterations || '';
        maxInput.addEventListener('input', () => {
            const v = parseInt(maxInput.value, 10);
            if (Number.isFinite(v) && v > 0) {
                step.max_iterations = v;
            } else {
                delete step.max_iterations;
            }
            this._syncJson();
        });
        maxGroup.appendChild(maxInput);
        row2.appendChild(maxGroup);
        body.appendChild(row2);

        // Step template (1 step rendu récursivement)
        const tplHeader = document.createElement('div');
        tplHeader.className = 'synapse-admin-mb-sm';
        tplHeader.innerHTML = '<span class="synapse-admin-label">Step template <span class="synapse-admin-text-tertiary">(exécuté pour chaque item)</span></span>';
        body.appendChild(tplHeader);

        if (!step.step) {
            step.step = this._createStepOfType('agent');
            step.step.name = 'template';
        }

        const tplCard = this._renderStep(step.step, {
            depth: options.depth + 1,
            index: 0,
            allowMove: false,  // un seul template, pas de move
            allowRemove: false,  // un seul template, pas de remove
            isFirst: true,
            isLast: true,
            onUpdate: options.onUpdate,
            onMoveUp: () => {},
            onMoveDown: () => {},
            onRemove: () => {},
        });
        body.appendChild(tplCard);

        // Aide contextuelle
        const help = document.createElement('p');
        help.className = 'synapse-admin-help-text synapse-admin-mt-sm';
        const alias = step.item_alias || 'item';
        help.innerHTML = 'L\'item courant est accessible via <code>$.inputs.' + escapeHtml(alias) + '</code> et l\'index via <code>$.inputs.index</code>. Outputs sous <code>$.steps.' + escapeHtml(step.name || '&lt;nom&gt;') + '.output.data.iterations</code>.';
        body.appendChild(help);
    }

    // ── Body — Sub-workflow ────────────────────────────────────────────────

    _renderSubWorkflowBody(step, body, options) {
        // Sélecteur de workflow cible
        const wfGroup = document.createElement('div');
        wfGroup.className = 'synapse-admin-form-group synapse-admin-mb-md';
        const wfLabel = document.createElement('label');
        wfLabel.className = 'synapse-admin-label';
        wfLabel.innerHTML = 'Workflow délégué <span class="synapse-admin-required">*</span>';
        wfGroup.appendChild(wfLabel);

        const wfSelect = document.createElement('select');
        wfSelect.className = 'synapse-admin-input';
        wfSelect.appendChild(new Option('— Sélectionne un workflow —', ''));

        let found = false;
        for (const wf of this.workflowsValue) {
            const opt = new Option(wf.name + ' (' + wf.key + ')', wf.key);
            if (step.workflow_key === wf.key) {
                opt.selected = true;
                found = true;
            }
            wfSelect.appendChild(opt);
        }
        if (step.workflow_key && !found) {
            const ghost = new Option('⚠️ ' + step.workflow_key + ' (introuvable ou inactif)', step.workflow_key);
            ghost.selected = true;
            wfSelect.appendChild(ghost);
        }
        wfSelect.addEventListener('change', () => {
            step.workflow_key = wfSelect.value;
            this._syncJson();
        });
        wfGroup.appendChild(wfSelect);

        const help = document.createElement('p');
        help.className = 'synapse-admin-help-text';
        help.textContent = 'Les workflows inactifs, éphémères, et le workflow courant sont exclus de la liste pour éviter les références circulaires.';
        wfGroup.appendChild(help);
        body.appendChild(wfGroup);

        // Section input_mapping (standard, comme pour agent)
        body.appendChild(this._buildMappingSection(step, options));
    }

    // ── Type change handling ───────────────────────────────────────────────

    _confirmTypeChange(step, oldType, newType) {
        // Champs critiques qui seront perdus par type
        const lossCheck = {
            agent: () => (step.agent_name && step.agent_name !== '') || (step.input_mapping && Object.keys(step.input_mapping).length > 0),
            conditional: () => step.condition && step.condition !== '',
            parallel: () => Array.isArray(step.branches) && step.branches.length > 0,
            loop: () => step.items_path || (step.step && step.step.agent_name),
            sub_workflow: () => step.workflow_key && step.workflow_key !== '',
        };
        const check = lossCheck[oldType];
        if (check && check()) {
            return window.confirm(`Changer le type de « ${step.name || 'ce step'} » de ${oldType} vers ${newType} effacera les champs spécifiques au type actuel. Continuer ?`);
        }
        return true;
    }

    _changeStepType(step, newType) {
        // Preserve le nom uniquement, efface tout le reste, puis merge avec
        // les champs par défaut du nouveau type.
        const preservedName = step.name;
        for (const key of Object.keys(step)) {
            delete step[key];
        }
        const defaults = this._createStepOfType(newType);
        Object.assign(step, defaults);
        step.name = preservedName;
    }

    // ── Body — Read-only (fallback pour types inconnus) ────────────────────

    _renderReadOnlyBody(step, stepType, body) {
        const alert = document.createElement('div');
        alert.className = 'synapse-admin-alert synapse-admin-alert--info';

        let content = '<div style="width:100%;">';
        content += '<div class="synapse-admin-text-sm synapse-admin-mb-sm"><strong>Step de type <code>' + escapeHtml(stepType) + '</code></strong> — édition visuelle arrivera en partie 2. Passe en mode JSON pour modifier.</div>';

        if (stepType === 'conditional') {
            content += '<div class="synapse-admin-text-xs"><strong>Condition</strong> : <code>' + escapeHtml(step.condition || '') + '</code></div>';
            if (step.equals !== undefined) {
                content += '<div class="synapse-admin-text-xs"><strong>Égale à</strong> : <code>' + escapeHtml(JSON.stringify(step.equals)) + '</code></div>';
            }
        } else if (stepType === 'parallel') {
            const branches = Array.isArray(step.branches) ? step.branches : [];
            content += '<div class="synapse-admin-text-xs"><strong>' + branches.length + ' branche(s)</strong>';
            if (branches.length > 0) {
                content += ' : ' + branches.map((b) => escapeHtml(b.name || '?')).join(', ');
            }
            content += '</div>';
        } else if (stepType === 'loop') {
            content += '<div class="synapse-admin-text-xs"><strong>Items</strong> : <code>' + escapeHtml(step.items_path || '') + '</code></div>';
            const tpl = step.step || {};
            content += '<div class="synapse-admin-text-xs"><strong>Template</strong> : <code>' + escapeHtml(tpl.name || '?') + '</code> (' + escapeHtml(tpl.type || 'agent') + ')</div>';
        } else if (stepType === 'sub_workflow') {
            content += '<div class="synapse-admin-text-xs"><strong>Workflow délégué</strong> : <code>' + escapeHtml(step.workflow_key || '') + '</code></div>';
        }
        content += '</div>';

        alert.innerHTML = '<i data-lucide="info"></i>' + content;
        body.appendChild(alert);
    }

    // ── Outputs ─────────────────────────────────────────────────────────────

    _renderOutputs() {
        this.outputsContainerTarget.innerHTML = '';
        const outputs = this.def.outputs || {};
        const keys = Object.keys(outputs);

        if (keys.length === 0) {
            this.outputsContainerTarget.innerHTML = '<div class="synapse-admin-text-xs synapse-admin-text-tertiary" style="padding:0.25rem 0;">Aucune sortie définie.</div>';
            return;
        }
        for (const k of keys) {
            this.outputsContainerTarget.appendChild(this._renderOutputRow(k));
        }
    }

    _renderOutputRow(key) {
        const row = document.createElement('div');
        row.className = 'synapse-wf-step__mapping-row';

        const value = this.def.outputs[key] || '';
        row.innerHTML =
            '<input type="text" class="synapse-admin-input synapse-wf-step__mapping-key" value="' + escapeHtml(key) + '" placeholder="nom de l\'output">' +
            '<select class="synapse-admin-input synapse-wf-step__mapping-source">' + this._sourceOptions(value) + '</select>' +
            '<button type="button" class="synapse-admin-btn synapse-admin-btn--icon synapse-admin-btn--ghost synapse-admin-btn--sm" data-action="remove-output"><i data-lucide="x"></i></button>';

        let currentKey = key;
        const keyInput = row.querySelector('.synapse-wf-step__mapping-key');
        keyInput.addEventListener('input', () => {
            const newKey = keyInput.value.replace(/[^a-zA-Z0-9_-]/g, '');
            keyInput.value = newKey;
            if (newKey !== currentKey && newKey) {
                const val = this.def.outputs[currentKey];
                delete this.def.outputs[currentKey];
                this.def.outputs[newKey] = val;
                currentKey = newKey;
                this._syncJson();
            }
        });

        row.querySelector('.synapse-wf-step__mapping-source').addEventListener('change', (e) => {
            this.def.outputs[currentKey] = e.target.value;
            this._syncJson();
        });

        row.querySelector('[data-action="remove-output"]').addEventListener('click', () => {
            delete this.def.outputs[currentKey];
            this._syncJson();
            this.render();
        });

        return row;
    }

    // ── Source options helper ──────────────────────────────────────────────

    _sourceOptions(currentValue) {
        const sel = (v) => (currentValue === v ? ' selected' : '');
        const isLiteral = currentValue !== undefined && currentValue !== null && !String(currentValue).startsWith('$');
        const noSelection = currentValue === undefined || currentValue === null;
        let html = '<option value=""' + (noSelection ? ' selected' : '') + '>-- Sélectionner --</option>';

        // Le message du chat (= $.inputs.message)
        const msgPath = '$.inputs.message';
        html += '<option value="' + escapeHtml(msgPath) + '"' + sel(msgPath) + '>Le message envoyé dans le chat</option>';

        // Autres inputs custom
        const inputs = this.def.inputs || {};
        for (const k of Object.keys(inputs)) {
            if (k === 'message') continue;
            const v = '$.inputs.' + k;
            html += '<option value="' + escapeHtml(v) + '"' + sel(v) + '>Input « ' + escapeHtml(k) + ' »</option>';
        }

        // Résultats des étapes précédentes (toutes les steps de la definition, tous types)
        for (let i = 0; i < this.def.steps.length; i++) {
            const s = this.def.steps[i];
            if (!s || !s.name) continue;
            const prefix = '$.steps.' + s.name + '.output.';
            html += '<option value="' + escapeHtml(prefix + 'text') + '"' + sel(prefix + 'text') + '>Réponse de « ' + escapeHtml(s.name) + ' »</option>';
            html += '<option value="' + escapeHtml(prefix + 'data') + '"' + sel(prefix + 'data') + '>Données JSON de « ' + escapeHtml(s.name) + ' »</option>';
        }

        // Texte fixe (instruction statique)
        html += '<option value="__literal__"' + (isLiteral ? ' selected' : '') + '>Texte fixe</option>';

        // Fallback: unknown path already stored
        if (currentValue && String(currentValue).startsWith('$')) {
            const exists = html.includes('value="' + escapeHtml(currentValue) + '"');
            if (!exists) {
                html += '<option value="' + escapeHtml(currentValue) + '" selected>' + escapeHtml(currentValue) + '</option>';
            }
        }

        return html;
    }

    // ── Mutations ──────────────────────────────────────────────────────────

    _createStepOfType(type) {
        const base = { name: 'step_' + (this.def.steps.length + 1), type: type };
        switch (type) {
            case 'agent':
                return { ...base, agent_name: '', input_mapping: {} };
            case 'conditional':
                return { ...base, condition: '' };
            case 'parallel':
                return { ...base, branches: [] };
            case 'loop':
                return { ...base, items_path: '', step: { name: 'template', type: 'agent', agent_name: '', input_mapping: {} } };
            case 'sub_workflow':
                return { ...base, workflow_key: '', input_mapping: {} };
            default:
                return base;
        }
    }

    _removeStep(index) {
        this.def.steps.splice(index, 1);
        this._syncJson();
        this.render();
    }

    _moveStep(index, direction) {
        const target = index + direction;
        if (target < 0 || target >= this.def.steps.length) return;
        const tmp = this.def.steps[index];
        this.def.steps[index] = this.def.steps[target];
        this.def.steps[target] = tmp;
        this._syncJson();
        this.render();
    }

    // ── Internals ──────────────────────────────────────────────────────────

    _syncJson() {
        this.definitionInputTarget.value = JSON.stringify(this.def, null, 2);
    }

    _refreshIcons() {
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    _setActiveButton(activeBtn, inactiveBtn) {
        activeBtn.className = 'synapse-admin-btn synapse-admin-btn--sm synapse-admin-btn--primary';
        inactiveBtn.className = 'synapse-admin-btn synapse-admin-btn--sm synapse-admin-btn--ghost';
    }
}
