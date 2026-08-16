import './bootstrap.js';
import './styles/app.css';
import '@hotwired/turbo';
import '@tailwindplus/elements';

// Dev uniquement : la toolbar de debug Symfony (id sfwdt<token>) n'est pas
// morph-compatible. Un refresh Turbo en morph la remplace par le markup du
// nouveau token sans réexécuter son <script>, et son JS orphelin crache des
// "Cannot read properties of null" à chaque requête ajax suivante. On la
// laisse hors du morph : l'ancienne toolbar (initialisée) reste en place.
// preventDefault couvre le morph ET le retrait; l'événement n'existe que
// pendant les refreshes morph, coût nul ailleurs. En prod, aucun sfwdt.
document.addEventListener('turbo:before-morph-element', (event) => {
    const id = event.target?.id ?? '';
    if (id.startsWith('sfwdt')) {
        event.preventDefault();
    }
});
// ...et comme chaque réponse embarque SA toolbar (nouveau token, donc un id
// que le morph ne peut pas apparier), les copies ajoutées sont retirées
// après coup : seule la toolbar d'origine, marquée au chargement, survit.
document.addEventListener('turbo:load', () => {
    document.querySelector('[id^="sfwdt"]')?.setAttribute('data-wdt-keep', '');
});
document.addEventListener('turbo:morph', () => {
    document.querySelectorAll('[id^="sfwdt"]:not([data-wdt-keep])').forEach((node) => node.remove());
});
