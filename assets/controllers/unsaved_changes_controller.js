/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Guards the document request form against losing edits on navigation.
 *
 * Tracks a "dirty" flag (any user edit since the last save/download) and, when
 * the admin tries to leave the page with pending changes, intercepts the
 * navigation to surface a confirmation modal: save & leave, leave without
 * saving, or stay.
 *
 * Wiring (on the <form>):
 *   data-controller="unsaved-changes"
 *   data-unsaved-changes-redirect-url-value="/.../documents"
 *   <input ...>                       → input/change bubble up → markDirty
 *   <button data-unsaved-changes-target="saveButton" type="submit">
 *   <dialog data-unsaved-changes-target="dialog">
 *     <button data-action="unsaved-changes#saveAndLeave">
 *     <button data-action="unsaved-changes#leaveWithoutSaving">
 *
 * The server-side LiveActions dispatch 'document-request:saved' and
 * 'document-request:download' on success; both clear the dirty flag, and a
 * pending "save & leave" continues the navigation once 'saved' arrives.
 *
 * Both modal choices land back on the documents hub (redirectUrl). The save
 * button is disabled while the form is pristine, so it only becomes clickable
 * once the admin actually changes something.
 *
 * Turbo (in-app) navigations are caught via turbo:before-visit and resumed
 * with Turbo.visit(). Hard unloads (tab close, reload, external links) fall
 * back to the browser's native beforeunload prompt.
 */
export default class extends Controller {
    static targets = ['dialog', 'saveButton'];
    static values = { redirectUrl: String };

    connect() {
        this.dirty = false;
        this.pendingUrl = null;
        this.leaving = false;

        this._onEdit = () => this.markDirty();
        this._onClean = () => this._handleClean();
        this._onBeforeVisit = (event) => this._beforeVisit(event);
        this._onBeforeUnload = (event) => this._beforeUnload(event);

        this.element.addEventListener('input', this._onEdit);
        this.element.addEventListener('change', this._onEdit);
        window.addEventListener('document-request:saved', this._onClean);
        window.addEventListener('document-request:download', this._onClean);
        document.addEventListener('turbo:before-visit', this._onBeforeVisit);
        window.addEventListener('beforeunload', this._onBeforeUnload);

        this._syncSaveButton();
    }

    // Re-apply the pristine/dirty disabled state after a LiveComponent morph
    // re-creates the button.
    saveButtonTargetConnected() {
        this._syncSaveButton();
    }

    disconnect() {
        this.element.removeEventListener('input', this._onEdit);
        this.element.removeEventListener('change', this._onEdit);
        window.removeEventListener('document-request:saved', this._onClean);
        window.removeEventListener('document-request:download', this._onClean);
        document.removeEventListener('turbo:before-visit', this._onBeforeVisit);
        window.removeEventListener('beforeunload', this._onBeforeUnload);
    }

    markDirty() {
        this.dirty = true;
        // A fresh manual edit cancels any in-flight "save & leave" intent so a
        // later successful save doesn't navigate away unexpectedly.
        this.leaving = false;
        this._syncSaveButton();
    }

    _handleClean() {
        this.dirty = false;
        this._syncSaveButton();
        if (this.leaving) {
            this.leaving = false;
            this.pendingUrl = null;
            this._close();
            this._visit(this._targetUrl());
        }
    }

    _beforeVisit(event) {
        if (!this.dirty) {
            return;
        }
        event.preventDefault();
        this.pendingUrl = event.detail?.url ?? null;
        this._open();
    }

    _beforeUnload(event) {
        if (!this.dirty) {
            return;
        }
        event.preventDefault();
        // Required by some browsers to actually show the native prompt.
        event.returnValue = '';
    }

    // ── Modal actions ────────────────────────────────────────────────────

    saveAndLeave() {
        if (!this.hasSaveButtonTarget) {
            // No way to save — fall back to leaving so the user isn't stuck.
            this.leaveWithoutSaving();
            return;
        }
        // Navigation resumes in _handleClean() once the server dispatches
        // 'document-request:saved'. If validation fails, no event fires and we
        // stay on the page with the errors visible.
        this.leaving = true;
        this._close();
        this.saveButtonTarget.click();
    }

    leaveWithoutSaving() {
        this.pendingUrl = null;
        this.leaving = false;
        this.dirty = false;
        this._syncSaveButton();
        this._close();
        this._visit(this._targetUrl());
    }

    // Bound to the dialog's `cancel` event (ESC) so the modal can still be
    // dismissed without leaving, even though the visible "cancel" button was
    // removed.
    cancel() {
        this.pendingUrl = null;
        this.leaving = false;
        this._close();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    _syncSaveButton() {
        if (this.hasSaveButtonTarget) {
            this.saveButtonTarget.disabled = !this.dirty;
        }
    }

    _targetUrl() {
        // Both modal choices return to the documents hub; fall back to the
        // intercepted destination, then to the current page.
        return this.redirectUrlValue || this.pendingUrl || window.location.href;
    }

    _open() {
        if (this.hasDialogTarget && !this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
    }

    _close() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    _visit(url) {
        // dirty is already false here, so the resulting before-visit passes
        // through without re-opening the modal.
        if (window.Turbo?.visit) {
            window.Turbo.visit(url);
        } else {
            window.location.href = url;
        }
    }
}
