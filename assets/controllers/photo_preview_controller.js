/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Thumbnail previews of a multiple <input type="file"> (visit property
 * photos): on change, renders one thumbnail per selected image in the grid
 * and keeps the "n/max" counter up to date. Selections beyond `max` are
 * trimmed client-side (the server enforces the same cap). Object URLs are
 * revoked on every re-render and on disconnect.
 */
export default class extends Controller {
    static targets = ['input', 'grid', 'counter'];
    static values = {
        max: { type: Number, default: 12 },
        coverLabel: { type: String, default: '' },
        makeCoverLabel: { type: String, default: '' },
    };

    #urls = [];

    changed() {
        this.#trimToMax();
        this.#render();
        this.#broadcast();
    }

    disconnect() {
        this.#revoke();
        this.#broadcast([]);
    }

    /* Le récap (carte d'annonce) affiche les photos choisies : il vit dans
       une autre branche du DOM, on lui relaie les object URLs par événement
       window (les URLs restent la propriété de ce controller). */
    #broadcast(urls = null) {
        window.dispatchEvent(new CustomEvent('visit-photos:changed', {
            detail: { urls: urls ?? [...this.#urls] },
        }));
    }

    #trimToMax() {
        const files = [...this.inputTarget.files];
        if (files.length <= this.maxValue) {
            return;
        }
        const transfer = new DataTransfer();
        files.slice(0, this.maxValue).forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
    }

    #render() {
        this.#revoke();
        if (!this.hasGridTarget) {
            return;
        }
        this.gridTarget.textContent = '';
        const files = [...this.inputTarget.files];

        files.forEach((file, index) => {
            const url = URL.createObjectURL(file);
            this.#urls.push(url);
            const img = document.createElement('img');
            img.src = url;
            img.alt = file.name;
            img.className = 'aspect-square w-full rounded-lg border border-neutral-200 object-cover';

            /* La première photo est la couverture (l'ordre d'envoi porte le
               choix) : cliquer une vignette la passe en tête. Discret : un
               petit badge sur la première, les autres au survol. */
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'group relative block w-full cursor-pointer';
            cell.appendChild(img);
            if (0 === index) {
                const badge = document.createElement('span');
                badge.textContent = this.coverLabelValue;
                badge.className = 'absolute bottom-1 left-1 rounded-full bg-gray-950/70 px-1.5 py-0.5 text-[10px] font-semibold text-white';
                cell.appendChild(badge);
                cell.title = this.coverLabelValue;
            } else {
                cell.title = this.makeCoverLabelValue;
                cell.setAttribute('aria-label', this.makeCoverLabelValue);
                const hint = document.createElement('span');
                hint.textContent = this.makeCoverLabelValue;
                hint.className = 'absolute inset-x-1 bottom-1 hidden truncate rounded-full bg-gray-950/70 px-1.5 py-0.5 text-center text-[10px] font-medium text-white group-hover:block';
                hint.setAttribute('aria-hidden', 'true');
                cell.appendChild(hint);
                cell.addEventListener('click', () => this.#makeCover(index));
            }
            this.gridTarget.appendChild(cell);
        });

        this.gridTarget.classList.toggle('hidden', 0 === files.length);
        this.gridTarget.classList.toggle('grid', files.length > 0);
        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${files.length}/${this.maxValue}`;
        }
    }

    /* Passe la photo en tête de l'input : premier fichier = couverture,
       partout (stockage, galerie, récap). */
    #makeCover(index) {
        const files = [...this.inputTarget.files];
        if (index <= 0 || index >= files.length) {
            return;
        }
        const [chosen] = files.splice(index, 1);
        const transfer = new DataTransfer();
        [chosen, ...files].forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
        this.#render();
        this.#broadcast();
    }

    #revoke() {
        this.#urls.forEach((url) => URL.revokeObjectURL(url));
        this.#urls = [];
    }
}
