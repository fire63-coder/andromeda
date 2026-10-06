/**
 * Composant Alpine qui relie CodeMirror à un composant Livewire :
 *
 *   <div x-data="sqlEditor({ doc, mode, schema })" wire:ignore>...<div x-ref="editor"></div></div>
 *
 * - chaque frappe met à jour $wire.sql localement (sans requête réseau) ;
 * - Ctrl/Cmd + Entrée appelle run(), Ctrl/Cmd + Maj + Entrée appelle submit() ;
 * - le serveur pilote l'éditeur via les événements « sql-editor:replace » et « sql-editor:mode ».
 */
export default function sqlEditor({ doc = '', mode = 'sqlite', schema = {} } = {}) {
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
                onChange: (value) => this.$wire.$set('sql', value, false),
                onRun: () => this.call('run'),
                onSubmit: () => this.call('submit'),
            });

            this.ready = true;
        },

        call(method) {
            this.$wire.$set('sql', editor.value(), false);
            this.$wire[method]();
        },

        replace(text) {
            editor?.replace(text);
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
