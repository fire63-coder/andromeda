/**
 * <div x-data="mermaidDiagram(@js($source))"><div x-ref="target"></div></div>
 * Mermaid est chargé à la demande, seulement sur les pages qui affichent un diagramme.
 */
let counter = 0;

export default function mermaidDiagram(source) {
    return {
        error: null,

        async init() {
            try {
                const { default: mermaid } = await import('mermaid');
                const dark = document.documentElement.classList.contains('dark') || window.matchMedia('(prefers-color-scheme: dark)').matches;

                mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'default' });
                const { svg } = await mermaid.render(`mermaid-${++counter}`, source);
                this.$refs.target.innerHTML = svg;
            } catch (e) {
                this.error = 'Diagramme invalide.';
            }
        },
    };
}
