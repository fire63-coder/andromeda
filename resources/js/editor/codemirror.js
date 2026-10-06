// Chargé à la demande (import dynamique) : CodeMirror n'alourdit que les pages d'exercice.
import { basicSetup } from 'codemirror';
import { EditorView, keymap } from '@codemirror/view';
import { Compartment, EditorState, Prec } from '@codemirror/state';
import { indentWithTab } from '@codemirror/commands';
import { MariaSQL, MSSQL, MySQL, PLSQL, PostgreSQL, SQLite, StandardSQL, sql } from '@codemirror/lang-sql';
import { oneDark } from '@codemirror/theme-one-dark';

// Clés = sql_dialects.editor_mode
const DIALECTS = {
    sqlite: SQLite,
    pgsql: PostgreSQL,
    mysql: MySQL,
    mariadb: MariaSQL,
    mssql: MSSQL,
    plsql: PLSQL,
};

const languageFor = (mode, schema) => sql({
    dialect: DIALECTS[mode] ?? StandardSQL,
    schema,
    upperCaseKeywords: true,
});

export function createSqlEditor({ parent, doc, mode, schema, onChange, onRun, onSubmit }) {
    const language = new Compartment();

    const view = new EditorView({
        parent,
        state: EditorState.create({
            doc,
            extensions: [
                basicSetup,
                oneDark,
                // Prioritaire sur basicSetup, qui associe déjà Mod-Enter à « insérer une ligne ».
                Prec.highest(keymap.of([
                    { key: 'Mod-Enter', preventDefault: true, run: () => (onRun(), true) },
                    { key: 'Mod-Shift-Enter', preventDefault: true, run: () => (onSubmit(), true) },
                    indentWithTab,
                ])),
                language.of(languageFor(mode, schema)),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        onChange(update.state.doc.toString());
                    }
                }),
                EditorView.theme({
                    '&': { height: '100%', fontSize: '14px' },
                    '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' },
                }),
            ],
        }),
    });

    return {
        value: () => view.state.doc.toString(),
        setMode: (nextMode) => view.dispatch({ effects: language.reconfigure(languageFor(nextMode, schema)) }),
        replace: (text) => view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } }),
        focus: () => view.focus(),
        destroy: () => view.destroy(),
    };
}
