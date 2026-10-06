/**
 * Composant Alpine qui relie CodeMirror à un composant Livewire :
 *
 *   <div x-data="sqlEditor({ doc, mode, schema })" wire:ignore>...<div x-ref="editor"></div></div>
 *
 * - chaque frappe met à jour la propriété Livewire `model` (« sql » par défaut) localement, sans requête ;
 * - Ctrl/Cmd + Entrée appelle `run`, Ctrl/Cmd + Maj + Entrée appelle `submit` (méthode + arguments) ;
 * - le serveur pilote l'éditeur via « sql-editor:replace » et « sql-editor:mode » (ciblés par `model`).
 *
 * Plusieurs éditeurs peuvent cohabiter (blocs SQL d'une leçon) : chacun a son `model`, ex. « snippets.2 ».
 */
export default function sqlEditor({
    doc = '',
    mode = 'sqlite',
    schema = {},
    model = 'sql',
    run = ['run'],
    submit = ['submit'],
} = {}) {
    let editor = null;

    return {
        ready: false,

        async init() {
            const { createSqlEditor } = await import('./codemirror');

            editor = createSqlEditor({
                parent: this.$refs.editor,
                doc,
                mode,
                schema,
                onChange: (value) => this.$wire.$set(model, value, false),
                onRun: () => this.call(run),
                onSubmit: () => this.call(submit),
            });

            this.ready = true;
        },

        call(action) {
            if (!action) {
                return;
            }

            const [method, ...args] = action;
            this.$wire.$set(model, editor.value(), false);
            this.$wire[method](...args);
        },

        replace(detail) {
            if ((detail.model ?? 'sql') !== model) {
                return;
            }

            editor?.replace(detail.sql);
            editor?.focus();
        },

        setMode(nextMode) {
            editor?.setMode(nextMode);
        },

        destroy() {
            editor?.destroy();
        },
    };
}
