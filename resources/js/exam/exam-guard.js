/**
 * Surveillance d'une épreuve en mode examen (composant Alpine).
 *
 * - plein écran exigé : tant qu'il n'est pas actif, un écran opaque masque l'épreuve ;
 * - copier, couper, coller, glisser-déposer et menu contextuel bloqués ;
 * - sorties (plein écran, onglet, autre application) signalées au serveur, qui les compte
 *   et clôt l'épreuve au-delà du nombre toléré.
 *
 * Ces mesures dissuadent sans être infaillibles (un second appareil reste possible) : le journal
 * des incidents est conservé avec la tentative pour le formateur.
 */
export default ({ count = 0, limit = null } = {}) => ({
    count,
    limit,
    fullscreen: Boolean(document.fullscreenElement),
    supported: Boolean(document.documentElement.requestFullscreen),
    warning: null,
    blurTimer: null,
    lastLogged: {},
    cleanup: [],

    init() {
        const listen = (target, event, handler) => {
            target.addEventListener(event, handler, true);
            this.cleanup.push(() => target.removeEventListener(event, handler, true));
        };

        const block = (type, message) => (event) => {
            event.preventDefault();
            event.stopPropagation();
            this.flash(message);
            this.log(type);
        };

        listen(document, 'copy', block('copy_blocked', 'La copie est désactivée pendant l\'épreuve.'));
        listen(document, 'cut', block('copy_blocked', 'La copie est désactivée pendant l\'épreuve.'));
        listen(document, 'paste', block('paste_blocked', 'Le collage est désactivé pendant l\'épreuve : saisissez votre réponse.'));
        listen(document, 'drop', block('drop_blocked', 'Le glisser-déposer est désactivé pendant l\'épreuve.'));
        listen(document, 'dragstart', (event) => event.preventDefault());
        listen(document, 'contextmenu', block('context_menu', 'Le menu contextuel est désactivé pendant l\'épreuve.'));
        listen(document, 'beforeinput', (event) => {
            if (event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop') {
                block('paste_blocked', 'Le collage est désactivé pendant l\'épreuve : saisissez votre réponse.')(event);
            }
        });

        listen(document, 'fullscreenchange', () => {
            this.fullscreen = Boolean(document.fullscreenElement);
            if (! this.fullscreen) {
                this.report('fullscreen_exit');
            }
        });

        listen(document, 'visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                this.report('tab_hidden');
            }
        });

        // Une perte de focus brève (boîte de dialogue, clic hors de la page) n'est pas comptée :
        // seulement une absence de plus de 3 secondes (passage à une autre application).
        listen(window, 'blur', () => {
            clearTimeout(this.blurTimer);
            this.blurTimer = setTimeout(() => this.report('window_blur'), 3000);
        });
        listen(window, 'focus', () => clearTimeout(this.blurTimer));

        // Livewire peut retirer le composant (fin de l'épreuve, navigation) : on rend la main.
        this.cleanup.push(() => clearTimeout(this.blurTimer));
    },

    destroy() {
        this.cleanup.forEach((undo) => undo());
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    },

    enterFullscreen() {
        document.documentElement.requestFullscreen({ navigationUI: 'hide' }).catch(() => {
            this.flash('Le navigateur a refusé le plein écran. Réessayez ou changez de navigateur.');
        });
    },

    /** Événement compté : le serveur décide (anti-rafale, seuil, clôture). */
    async report(type) {
        const outcome = await this.$wire.reportIncident(type);

        if (! outcome) {
            return;
        }

        this.count = outcome.count;
        this.limit = outcome.limit;

        if (outcome.closed) {
            this.flash('Épreuve close : trop de sorties de l\'environnement d\'examen.');
        } else if (outcome.counted) {
            this.flash(this.limit === null
                ? `Incident enregistré (${this.count}). Restez en plein écran sur cet onglet.`
                : `Incident ${this.count} sur ${this.limit} tolérés. Au-delà, l'épreuve sera close.`);
        }
    },

    /** Événement seulement journalisé : au plus un envoi toutes les 10 secondes par type. */
    log(type) {
        const now = Date.now();
        if ((this.lastLogged[type] ?? 0) + 10000 < now) {
            this.lastLogged[type] = now;
            this.$wire.reportIncident(type);
        }
    },

    flash(message) {
        this.warning = message;
        clearTimeout(this.warningTimer);
        this.warningTimer = setTimeout(() => this.warning = null, 6000);
    },
});
