import './bootstrap';
import sqlEditor from './editor/sql-editor';

// Alpine est fourni par Livewire : on y enregistre nos composants avant son démarrage.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sqlEditor', sqlEditor);
});
