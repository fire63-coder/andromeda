import './bootstrap';
import sqlEditor from './editor/sql-editor';
import mermaidDiagram from './diagrams/mermaid';
import examGuard from './exam/exam-guard';

// Alpine est fourni par Livewire : on y enregistre nos composants avant son démarrage.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sqlEditor', sqlEditor);
    window.Alpine.data('mermaidDiagram', mermaidDiagram);
    window.Alpine.data('examGuard', examGuard);
});
