import './bootstrap';
import sqlEditor from './editor/sql-editor';
import mermaidDiagram from './diagrams/mermaid';

// Alpine est fourni par Livewire : on y enregistre nos composants avant son démarrage.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sqlEditor', sqlEditor);
    window.Alpine.data('mermaidDiagram', mermaidDiagram);
});
