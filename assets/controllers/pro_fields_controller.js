/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Professional pane of a dossier person. The whole pane only exists for the
 * roles listed in data-show-role on the pane (tenant: the other roles have
 * no rental application to build), following the role radios live. Inside,
 * each field shows only for the situations it exists for
 * (data-show-for="cdi,cdd" on the field wrapper), and the "no professional
 * activity" checkbox collapses the fields. Mirrors
 * PersonsManager::PRO_FIELD_PROFESSIONS, which clears the hidden values
 * server-side on save.
 */
export default class extends Controller {
    static targets = ['none', 'status', 'field', 'role', 'pane'];

    connect() {
        this.refresh();
    }

    refresh() {
        const role = this.roleTargets.find((radio) => radio.checked)?.value ?? '';
        for (const pane of this.paneTargets) {
            const showRole = pane.dataset.showRole;
            pane.hidden = showRole !== undefined && this.hasRoleTarget && !showRole.split(',').includes(role);
        }

        const none = this.hasNoneTarget && this.noneTarget.checked;
        const current = this.statusTargets.find((radio) => radio.checked)?.value ?? '';
        for (const field of this.fieldTargets) {
            const showFor = field.dataset.showFor;
            field.hidden = none || (showFor !== undefined && !showFor.split(',').includes(current));
        }
    }
}
