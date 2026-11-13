import Swal from 'sweetalert2';
import { gsap } from 'gsap';

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    customClass: {
        popup: 'instruments-desk-swal-toast',
        title: 'instruments-desk-swal-title',
        timerProgressBar: 'instruments-desk-swal-progress',
    },
    showClass: {
        popup: 'instruments-desk-swal-show',
    },
    hideClass: {
        popup: 'instruments-desk-swal-hide',
    },
});

const notificationKey = (type, message) => `${type}:${message}`;
const recentNotifications = new Map();
const recentTerminalSuccesses = new Map();

function notify(type, message) {
    const key = notificationKey(type, message);
    const now = Date.now();
    const lastShownAt = recentNotifications.get(key) ?? 0;

    if (now - lastShownAt < 750) {
        return;
    }

    recentNotifications.set(key, now);
    toast.fire({
        icon: type,
        title: message,
    });
}

window.addEventListener('instruments-desk-flash-notification', (event) => {
    const { type = 'success', message = '' } = event.detail ?? {};

    if (message !== '') {
        notify(type, message);
    }
});

function formatExternalPdfSize(bytes) {
    if (!Number.isFinite(bytes) || bytes < 1) {
        return '';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

let pendingExternalPdfUpload = null;
let pendingExternalPdfRemoval = null;

function resolveExternalPdfUpload(outcome) {
    if (pendingExternalPdfUpload === null) {
        return;
    }

    pendingExternalPdfUpload.resolve(outcome);
}

function resolveExternalPdfRemoval(outcome) {
    if (pendingExternalPdfRemoval === null) {
        return;
    }

    pendingExternalPdfRemoval.resolve(outcome);
}

function clearExternalPdfSelection(form, { syncComponent = true } = {}) {
    if (!form) {
        return;
    }

    const input = form.querySelector('[data-external-pdf-input]');
    const summary = form.querySelector('[data-external-pdf-summary]');
    const submit = form.querySelector('[data-external-pdf-submit]');
    const progressContainer = form.querySelector('[data-external-pdf-progress-container]');
    const progress = form.querySelector('[data-external-pdf-progress]');
    const progressFill = form.querySelector('[data-external-pdf-progress-fill]');
    const progressValue = form.querySelector('[data-external-pdf-progress-value]');
    const ready = form.querySelector('[data-external-pdf-ready]');

    if (input) {
        input.value = '';
    }

    if (summary) {
        summary.classList.add('hidden');
        summary.classList.remove('flex');
    }

    if (submit) {
        submit.disabled = true;
    }

    progressContainer?.classList.add('hidden');
    ready?.classList.add('hidden');
    if (progress) {
        progress.setAttribute('aria-valuenow', '0');
    }
    if (progressFill) {
        progressFill.style.width = '0%';
    }
    if (progressValue) {
        progressValue.textContent = '0%';
    }

    if (input) {
        delete input.dataset.externalPdfReady;
        delete input.dataset.externalPdfUploading;
    }

    const componentId = form.closest('[data-component-id]')?.dataset.componentId;
    const component = typeof componentId === 'string' ? window.Livewire?.find(componentId) : null;

    if (syncComponent && component) {
        component.set('externalCertificatePdf', null);
    }
}

function updateExternalPdfProgress(input, percentage) {
    const form = input.closest('[data-external-pdf-form]');
    const progressContainer = form?.querySelector('[data-external-pdf-progress-container]');
    const progress = form?.querySelector('[data-external-pdf-progress]');
    const progressFill = form?.querySelector('[data-external-pdf-progress-fill]');
    const progressValue = form?.querySelector('[data-external-pdf-progress-value]');
    const ready = form?.querySelector('[data-external-pdf-ready]');
    const value = Math.min(100, Math.max(0, Math.round(Number(percentage) || 0)));

    if (!form || !progressContainer || !progress || !progressFill || !progressValue) {
        return;
    }

    progressContainer.classList.remove('hidden');
    ready?.classList.add('hidden');
    progress.setAttribute('aria-valuenow', String(value));
    progressFill.style.width = `${value}%`;
    progressValue.textContent = `${value}%`;
}

function setExternalPdfPreview(form, file) {
    const input = form.querySelector('[data-external-pdf-input]');
    const summary = form.querySelector('[data-external-pdf-summary]');
    const name = form.querySelector('[data-external-pdf-name]');
    const meta = form.querySelector('[data-external-pdf-meta]');
    const submit = form.querySelector('[data-external-pdf-submit]');

    if (name) {
        name.textContent = file.name;
    }

    if (meta) {
        meta.textContent = `Selected file — not uploaded yet · ${formatExternalPdfSize(file.size)}`;
    }

    summary?.classList.remove('hidden');
    summary?.classList.add('flex');

    if (input) {
        input.dataset.externalPdfReady = 'false';
        input.dataset.externalPdfUploading = 'true';
    updateExternalPdfProgress(input, 0);
    }

    if (submit) {
        submit.disabled = true;
    }
}

function setExternalPdfUploadFinished(input) {
    const form = input.closest('[data-external-pdf-form]');
    const submit = form?.querySelector('[data-external-pdf-submit]');
    const progressContainer = form?.querySelector('[data-external-pdf-progress-container]');
    const ready = form?.querySelector('[data-external-pdf-ready]');

    if (!form || !submit || !input.files?.[0]) {
        return;
    }

    input.dataset.externalPdfReady = 'true';
    input.dataset.externalPdfUploading = 'false';
    progressContainer?.classList.add('hidden');
    ready?.classList.remove('hidden');
    submit.disabled = false;
}

async function confirmExternalPdfUpload({ componentId, fileName, fileSize }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (! component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    let uploading = false;
    let outcome = null;
    const sizeLabel = formatExternalPdfSize(Number(fileSize));
    const result = await Swal.fire({
        icon: 'question',
        title: 'Upload Certificate?',
        html: `<p class="instruments-desk-swal-text">Are you sure you want to upload this external calibration certificate?</p><p class="instruments-desk-swal-text"><strong>${escapeHtml(fileName || 'Selected PDF')}</strong>${sizeLabel ? `<br>${escapeHtml(sizeLabel)}` : ''}</p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Upload PDF',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (uploading) {
                return undefined;
            }

            uploading = true;
            Swal.update({
                title: 'Saving Certificate...',
                html: '<p class="instruments-desk-swal-text">Please wait while your certificate is being saved.</p>',
            });
            Swal.showLoading();

            const outcomePromise = new Promise((resolve) => {
                pendingExternalPdfUpload = { resolve };
            });

            try {
                await component.call('uploadExternalDocument');
                outcome = await outcomePromise;
            } catch (error) {
                uploading = false;
                outcome = {
                    success: false,
                    message: 'The certificate upload could not be completed. Please try again.',
                };
            } finally {
                pendingExternalPdfUpload = null;
            }

            return true;
        },
    });

    if (result.isConfirmed && outcome?.success === true) {
        await Swal.fire({
            icon: 'success',
            title: 'Upload Successful!',
            text: 'Certificate PDF has been uploaded successfully.',
            customClass: {
                popup: 'instruments-desk-swal-modal',
                title: 'instruments-desk-swal-title',
                confirmButton: 'instruments-desk-swal-confirm',
            },
        });
    }

    if (result.isConfirmed && outcome?.success === false) {
        await Swal.fire({
            icon: 'error',
            title: 'Upload Failed',
            text: outcome.message,
            customClass: {
                popup: 'instruments-desk-swal-modal',
                title: 'instruments-desk-swal-title',
                confirmButton: 'instruments-desk-swal-confirm',
            },
        });
    }

    return result;
}

async function confirmExternalPdfRemoval({ componentId, documentId, fileName }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(documentId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    let removing = false;
    let outcome = null;
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remove PDF?',
        html: `<p class="instruments-desk-swal-text">This PDF will be removed from the active calibration document. The file will remain available in document history.</p><p class="instruments-desk-swal-text"><strong>${escapeHtml(fileName || 'Selected PDF')}</strong></p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Remove PDF',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (removing) {
                return undefined;
            }

            removing = true;
            Swal.update({
                title: 'Removing PDF...',
                html: '<p class="instruments-desk-swal-text">Please wait while the active document is being removed.</p>',
            });
            Swal.showLoading();

            const outcomePromise = new Promise((resolve) => {
                pendingExternalPdfRemoval = { resolve };
            });

            try {
                await component.call('removeExternalDocument', Number(documentId));
                outcome = await outcomePromise;
            } catch (error) {
                outcome = {
                    success: false,
                    message: 'The certificate could not be removed. Please try again.',
                };
            } finally {
                pendingExternalPdfRemoval = null;
            }

            return true;
        },
    });

    if (result.isConfirmed && outcome?.success === true) {
        await Swal.fire({
            icon: 'success',
            title: 'PDF Removed Successfully!',
            text: 'The active PDF was removed and remains available in document history.',
            customClass: {
                popup: 'instruments-desk-swal-modal',
                title: 'instruments-desk-swal-title',
                confirmButton: 'instruments-desk-swal-confirm',
            },
        });
    }

    if (result.isConfirmed && outcome?.success === false) {
        await Swal.fire({
            icon: 'error',
            title: 'PDF Removal Failed',
            text: outcome.message,
            customClass: {
                popup: 'instruments-desk-swal-modal',
                title: 'instruments-desk-swal-title',
                confirmButton: 'instruments-desk-swal-confirm',
            },
        });
    }

    return result;
}

function registerExternalPdfUpload() {
    if (window.__instrumentsDeskExternalPdfUploadRegistered) {
        return;
    }

    window.__instrumentsDeskExternalPdfUploadRegistered = true;

    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (!input) {
            return;
        }

        const form = input.closest('[data-external-pdf-form]');
        const file = input.files?.[0];

        if (!form || !file) {
            return;
        }

        const maxBytes = Number(input.closest('[data-component-id]')?.dataset.externalPdfMaxBytes ?? 0);
        const isPdf = file.name.toLowerCase().endsWith('.pdf') && (!file.type || file.type === 'application/pdf');

        if (!isPdf) {
            notify('error', 'Only PDF files are allowed.');
            clearExternalPdfSelection(form);

            return;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
            notify('error', 'The selected PDF exceeds the maximum allowed file size.');
            clearExternalPdfSelection(form);

            return;
        }

        setExternalPdfPreview(form, file);
    });

    document.addEventListener('livewire-upload-start', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (input) {
            input.dataset.externalPdfUploading = 'true';
            input.dataset.externalPdfReady = 'false';
            input.closest('[data-external-pdf-form]')?.querySelector('[data-external-pdf-submit]')?.setAttribute('disabled', 'disabled');
        }
    });

    document.addEventListener('livewire-upload-finish', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (input) {
            setExternalPdfUploadFinished(input);
        }
    });

    document.addEventListener('livewire-upload-progress', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (input) {
            updateExternalPdfProgress(input, event.detail?.progress);
        }
    });

    document.addEventListener('livewire-upload-error', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (input) {
            notify('error', 'The PDF could not be prepared for upload. Please try again.');
            clearExternalPdfSelection(input.closest('[data-external-pdf-form]'));
        }
    });

    document.addEventListener('livewire-upload-cancel', (event) => {
        const input = event.target.closest('[data-external-pdf-input]');

        if (input) {
            clearExternalPdfSelection(input.closest('[data-external-pdf-form]'));
        }
    });

    document.addEventListener('click', (event) => {
        const picker = event.target.closest('[data-external-pdf-picker]');
        const remove = event.target.closest('[data-external-pdf-remove]');
        const removeDocument = event.target.closest('[data-external-pdf-remove-document]');

        if (removeDocument) {
            event.preventDefault();
            const root = removeDocument.closest('[data-component-id]');

            void confirmExternalPdfRemoval({
                componentId: root?.dataset.componentId,
                documentId: removeDocument.dataset.documentId,
                fileName: removeDocument.dataset.fileName,
            });

            return;
        }

        if (picker) {
            event.preventDefault();
            picker.closest('[data-external-pdf-form]')?.querySelector('[data-external-pdf-input]')?.click();

            return;
        }

        if (remove) {
            event.preventDefault();
            clearExternalPdfSelection(remove.closest('[data-external-pdf-form]'));
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-external-pdf-form]');

        if (!form) {
            return;
        }

        event.preventDefault();

        const input = form.querySelector('[data-external-pdf-input]');
        const file = input?.files?.[0];

        if (!file) {
            notify('error', 'Please select a PDF file.');

            return;
        }

        if (input.dataset.externalPdfUploading === 'true' || input.dataset.externalPdfReady !== 'true') {
            notify('info', 'Please wait until the selected PDF is ready to upload.');

            return;
        }

        const componentId = form.closest('[data-component-id]')?.dataset.componentId;

        void confirmExternalPdfUpload({
            componentId,
            fileName: file.name,
            fileSize: file.size,
        });
    });
}

async function showTerminalSuccess({ icon, title, text, confirmText, calibrationId }) {
    const key = `${calibrationId ?? 'unknown'}:${icon}:${title}:${text}:${confirmText}`;
    const now = Date.now();
    const lastShownAt = recentTerminalSuccesses.get(key) ?? 0;

    if (now - lastShownAt < 750) {
        return;
    }

    recentTerminalSuccesses.set(key, now);

    const result = await Swal.fire({
        icon,
        title,
        text,
        confirmButtonText: confirmText,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
        },
    });

    if (result.isConfirmed) {
        window.location.reload();
    }
}

async function confirmCancellation({ componentId, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text,
        input: 'textarea',
        inputLabel: 'Cancellation Reason',
        inputPlaceholder: 'Enter the mandatory cancellation reason',
        inputAttributes: {
            'aria-label': 'Cancellation Reason',
            maxlength: 5000,
        },
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            input: 'instruments-desk-swal-input',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        inputValidator: (value) => {
            if (!value || value.trim() === '') {
                return 'Cancellation reason is required.';
            }

            return undefined;
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.set('cancellationReason', result.value);
    await component.call('cancelSession');
}

async function confirmUserStatusChange({ componentId, userId, userName, activate = false }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(userId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The selected user is no longer available.');

        return;
    }

    const action = activate ? 'activate' : 'deactivate';
    const actionLabel = activate ? 'Activate User' : 'Deactivate User';
    const description = activate
        ? 'This user will be able to access Instruments Desk again using their existing credentials.'
        : 'This user will no longer be able to access Instruments Desk until their account is reactivated.';
    let processing = false;

    await Swal.fire({
        icon: activate ? 'success' : 'warning',
        title: `${activate ? 'Activate' : 'Deactivate'} User?`,
        html: `<p class="instruments-desk-swal-text">Are you sure you want to ${action} <strong>${escapeHtml(userName ?? 'this user')}</strong>?</p><p class="instruments-desk-swal-text">${description}</p>`,
        showCancelButton: true,
        confirmButtonText: actionLabel,
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (processing) {
                return undefined;
            }

            processing = true;

            try {
                await component.call('toggleStatus', Number(userId));
            } catch (error) {
                processing = false;
                Swal.showValidationMessage(`The user could not be ${action}d. Please try again.`);

                return undefined;
            }

            return true;
        },
    });
}

async function confirmCertificateRefresh(form, message) {
    if (!(form instanceof HTMLFormElement) || Swal.isVisible()) {
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Update Certificate?',
        text: message,
        showCancelButton: true,
        confirmButtonText: 'Yes, Update',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (result.isConfirmed) {
        form.submit();
    }
}

async function confirmStartCalibration({
    componentId,
    instrumentName,
    instrumentType,
    serialNumber,
    calibrationMethod,
}) {
    if (typeof componentId !== 'string' || !['INTERNAL', 'EXTERNAL'].includes(calibrationMethod) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration start form is no longer available.');

        return;
    }

    const optionalValue = (value) => value || '—';
    const methodDescription = calibrationMethod === 'INTERNAL'
        ? 'Temperature and Relative Humidity will be recorded during the calibration session.'
        : 'External calibration documents will be attached during the calibration session.';
    let starting = false;

    await Swal.fire({
        icon: 'question',
        title: 'Start Calibration?',
        html: `
            <p class="instruments-desk-swal-text">Are you sure you want to start a new calibration session for this instrument?</p>
            <div class="instruments-desk-swal-record">
                <p><strong>Instrument Name:</strong> ${escapeHtml(optionalValue(instrumentName))}</p>
                <p><strong>Instrument Type:</strong> ${escapeHtml(optionalValue(instrumentType))}</p>
                <p><strong>Serial Number:</strong> ${escapeHtml(optionalValue(serialNumber))}</p>
                <p><strong>Calibration Method:</strong> <strong>${escapeHtml(calibrationMethod)}</strong></p>
            </div>
            <p class="instruments-desk-swal-publish-warning">${escapeHtml(methodDescription)}</p>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Start Calibration',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (starting) {
                return undefined;
            }

            const methodInput = document.querySelector('#calibration-method');

            if (methodInput && methodInput.value !== calibrationMethod) {
                Swal.showValidationMessage('The calibration method changed. Please start the confirmation again.');

                return undefined;
            }

            starting = true;

            try {
                const response = await component.call('startCalibration');

                if (response?.started === false) {
                    starting = false;
                    Swal.showValidationMessage(response.message ?? 'Calibration could not be started. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                starting = false;
                Swal.showValidationMessage('Calibration could not be started. Please try again.');

                return undefined;
            }
        },
    });
}

async function confirmCompletion({ componentId, completionMethod, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string' || typeof completionMethod !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.call(completionMethod);
}

async function confirmActivityRemoval({ componentId, activityId, activityName, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text: text ?? `Remove ${activityName ?? 'this activity'} from the calibration session?`,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.set('removingActivityId', Number(activityId));
    await component.call('removeActivity');
}

async function confirmStandardRemoval({ componentId, standardId, standardName, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text: text ?? `Remove ${standardName ?? 'this standard'} from the selection?`,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.call('removeDraftStandard', Number(standardId));
}

async function confirmStandardAssignmentRemoval({ componentId, assignmentId, standardName }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(assignmentId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The standard assignment is no longer available.');

        return;
    }

    let removing = false;
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remove Standard Assignment?',
        html: `<p class="instruments-desk-swal-text">Remove <strong>${escapeHtml(standardName || 'this standard')}</strong> from the current Instrument Type?</p><p class="instruments-desk-swal-text">The Standard Master record will remain registered.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Remove Assignment',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (removing) {
                return undefined;
            }

            removing = true;

            try {
                const response = await component.call('removeAssignment', Number(assignmentId));

                if (!response?.removed) {
                    removing = false;
                    Swal.showValidationMessage(response?.message ?? 'The standard assignment could not be removed.');

                    return undefined;
                }

                return response;
            } catch (error) {
                removing = false;
                Swal.showValidationMessage('The standard assignment could not be removed. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: 'Standard Assignment Removed',
        });
    }
}

async function confirmStandardAssignment({
    componentId,
    standardId,
    standardCode,
    instrumentName,
    typeSpecification,
    serialNumber,
}) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The standard assignment form is no longer available.');

        return;
    }

    const optionalValue = (value) => value || '—';
    let assigning = false;
    const result = await Swal.fire({
        icon: 'question',
        title: 'Assign This Standard?',
        html: `
            <p class="instruments-desk-swal-text">Are you sure you want to assign this standard to the current Instrument Type?</p>
            <div class="instruments-desk-swal-record">
                <p><strong>Standard ID:</strong> ${escapeHtml(optionalValue(standardCode))}</p>
                <p><strong>Instrument Name:</strong> ${escapeHtml(optionalValue(instrumentName))}</p>
                <p><strong>Type / Specification:</strong> ${escapeHtml(optionalValue(typeSpecification))}</p>
                <p><strong>Serial Number:</strong> ${escapeHtml(optionalValue(serialNumber))}</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Assign Standard',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (assigning) {
                return undefined;
            }

            assigning = true;

            try {
                const response = await component.call('assignStandard');

                if (!response?.assigned) {
                    assigning = false;
                    Swal.showValidationMessage(response?.message ?? 'The standard could not be assigned. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                assigning = false;
                Swal.showValidationMessage('The standard could not be assigned. Please try again.');

                return undefined;
            }
        },
    });

    if (!result.isConfirmed) {
        await component.call('clearSelection');

        return;
    }

    toast.fire({
        icon: 'success',
        title: 'Standard Assigned Successfully!',
        text: 'The standard has been assigned to this Instrument Type.',
    });
}

async function confirmNewStandardCreation({ componentId, instrumentName }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The standard form is no longer available.');

        return;
    }

    let creating = false;
    const result = await Swal.fire({
        icon: 'question',
        title: 'Create New Standard?',
        html: `<p class="instruments-desk-swal-text">Are you sure you want to create this standard?</p><p class="instruments-desk-swal-text"><strong>${escapeHtml(instrumentName ?? '')}</strong></p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Create Standard',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (creating) {
                return undefined;
            }

            creating = true;

            try {
                const response = await component.call('createStandard');

                if (!response?.created) {
                    creating = false;

                    if (response?.validation) {
                        Swal.close();

                        return undefined;
                    }

                    Swal.showValidationMessage('The standard could not be created. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                creating = false;
                Swal.showValidationMessage('The standard could not be created. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: 'Standard Created Successfully!',
            text: 'The new standard has been added to the Standard Master.',
        });
    }
}

async function confirmStandardMasterSave({ componentId, mode, standardId, instrumentName }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The Standard Master form is no longer available.');

        return;
    }

    const editing = mode === 'update';
    let saving = false;
    const result = await Swal.fire({
        icon: 'question',
        title: editing ? 'Update This Standard?' : 'Create New Standard?',
        html: `<p class="instruments-desk-swal-text">${editing ? 'Are you sure you want to save these changes?' : 'Are you sure you want to create this standard?'}</p><p class="instruments-desk-swal-text"><strong>${escapeHtml(instrumentName ?? '')}</strong>${editing && standardId ? `<br>Standard ID: ${escapeHtml(String(standardId))}` : ''}</p>`,
        showCancelButton: true,
        confirmButtonText: editing ? 'Yes, Save Changes' : 'Yes, Create Standard',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            saving = true;

            try {
                const response = await component.call('saveStandard');

                if (!response?.created && !response?.updated) {
                    saving = false;

                    if (response?.validation) {
                        Swal.close();

                        return undefined;
                    }

                    Swal.showValidationMessage(response?.message ?? 'The standard could not be saved. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.showValidationMessage('The standard could not be saved. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: editing ? 'Standard Updated Successfully!' : 'Standard Created Successfully!',
        });
    }
}

async function confirmStandardMasterDelete({ componentId, standardId, standardCode, instrumentName, serialNumber }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The Standard Master record is no longer available.');

        return;
    }

    let deleting = false;
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Delete This Standard?',
        html: `<p class="instruments-desk-swal-text">Are you sure you want to delete this standard?</p><div class="instruments-desk-swal-record"><p><strong>Standard ID:</strong> ${escapeHtml(standardCode ?? standardId ?? '—')}</p><p><strong>Instrument Name:</strong> ${escapeHtml(instrumentName ?? '—')}</p><p><strong>Serial Number:</strong> ${escapeHtml(serialNumber || '—')}</p></div>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete Standard',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (deleting) {
                return undefined;
            }

            deleting = true;

            try {
                const response = await component.call('removeStandard');

                if (!response?.deleted) {
                    deleting = false;
                    Swal.showValidationMessage(response?.message ?? 'The standard could not be deleted.');

                    return undefined;
                }

                return response;
            } catch (error) {
                deleting = false;
                Swal.showValidationMessage('The standard could not be deleted. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: 'Standard Deleted Successfully!',
        });
    }
}

async function confirmStandardMasterDiscard({ componentId }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        return;
    }

    const fields = document.querySelectorAll(`[data-component-id="${CSS.escape(componentId)}"] [data-standard-form-field]`);
    const hasChanges = [...fields].some((field) => field.value !== (field.dataset.initialValue ?? ''));

    if (!hasChanges) {
        await component.call('cancelForm');

        return;
    }

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Discard Changes?',
        text: 'Your unsaved changes will be lost.',
        showCancelButton: true,
        confirmButtonText: 'Discard Changes',
        cancelButtonText: 'Keep Editing',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (result.isConfirmed) {
        await component.call('cancelForm');
    }
}

async function confirmActivityMasterSave({ componentId, mode, activityId, activityCode, activityName }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The Activity Master form is no longer available.');

        return;
    }

    const editing = mode === 'update';
    let saving = false;
    const result = await Swal.fire({
        icon: 'question',
        title: editing ? 'Update This Activity?' : 'Create New Activity?',
        html: `<p class="instruments-desk-swal-text">${editing ? 'Are you sure you want to save these changes?' : 'Are you sure you want to create this activity?'}</p><p class="instruments-desk-swal-text">${editing && activityCode ? `<strong>Activity Code:</strong> ${escapeHtml(activityCode)}<br>` : ''}<strong>Activity Name:</strong> ${escapeHtml(activityName ?? '')}</p>`,
        showCancelButton: true,
        confirmButtonText: editing ? 'Yes, Save Changes' : 'Yes, Create Activity',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            saving = true;

            try {
                const response = await component.call('saveActivity');

                if (!response?.created && !response?.updated) {
                    saving = false;

                    if (response?.validation) {
                        Swal.close();

                        return undefined;
                    }

                    Swal.showValidationMessage(response?.message ?? 'The activity could not be saved. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.showValidationMessage('The activity could not be saved. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: editing ? 'Activity Updated Successfully!' : 'Activity Created Successfully!',
        });
    }
}

async function confirmActivityMasterStatus({ componentId, activityId, activityCode, activityName, activate }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The Activity Master record is no longer available.');

        return;
    }

    let processing = false;
    const result = await Swal.fire({
        icon: 'warning',
        title: activate ? 'Reactivate This Activity?' : 'Deactivate This Activity?',
        html: `<p class="instruments-desk-swal-text">${activate ? 'Are you sure you want to reactivate this activity?' : 'Are you sure you want to deactivate this activity?'}</p><p class="instruments-desk-swal-text"><strong>Activity Code:</strong> ${escapeHtml(activityCode ?? '—')}<br><strong>Activity Name:</strong> ${escapeHtml(activityName ?? '—')}</p>${activate ? '' : '<p class="instruments-desk-swal-text">This activity will no longer be available for new selections. Existing calibration history will be preserved.</p>'}`,
        showCancelButton: true,
        confirmButtonText: activate ? 'Yes, Reactivate' : 'Yes, Deactivate',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (processing) {
                return undefined;
            }

            processing = true;

            try {
                const response = await component.call(activate ? 'activateActivity' : 'deactivateActivity', Number(activityId));
                const successful = activate ? response?.reactivated : response?.deactivated;

                if (!successful) {
                    processing = false;
                    Swal.showValidationMessage(response?.message ?? 'The activity status could not be changed.');

                    return undefined;
                }

                return response;
            } catch (error) {
                processing = false;
                Swal.showValidationMessage('The activity status could not be changed. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: activate ? 'Activity Reactivated Successfully!' : 'Activity Deactivated Successfully!',
        });
    }
}

async function confirmActivityMasterDelete({ componentId, activityId, activityCode, activityName }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The Activity Master record is no longer available.');

        return;
    }

    let deleting = false;
    const result = await Swal.fire({
        icon: 'warning',
        title: 'Delete This Activity?',
        html: `<p class="instruments-desk-swal-text">Are you sure you want to delete this activity?</p><p class="instruments-desk-swal-text"><strong>Activity Code:</strong> ${escapeHtml(activityCode ?? '—')}<br><strong>Activity Name:</strong> ${escapeHtml(activityName ?? '—')}</p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete Activity',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (deleting) {
                return undefined;
            }

            deleting = true;

            try {
                const response = await component.call('removeActivity');

                if (!response?.deleted) {
                    deleting = false;
                    Swal.showValidationMessage(response?.message ?? 'The activity could not be deleted.');

                    return undefined;
                }

                return response;
            } catch (error) {
                deleting = false;
                Swal.showValidationMessage('The activity could not be deleted. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: 'Activity Deleted Successfully!',
        });
    }
}

async function confirmActivityMasterDiscard({ componentId }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        return;
    }

    const fields = document.querySelectorAll(`[data-component-id="${CSS.escape(componentId)}"] [data-activity-form-field]`);
    const hasChanges = [...fields].some((field) => field.value !== (field.dataset.initialValue ?? ''));

    if (!hasChanges) {
        await component.call('cancelForm');

        return;
    }

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Discard Changes?',
        text: 'Your unsaved changes will be lost.',
        showCancelButton: true,
        confirmButtonText: 'Discard Changes',
        cancelButtonText: 'Keep Editing',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (result.isConfirmed) {
        await component.call('cancelForm');
    }
}

async function confirmIdentityUpdate({ componentId, instrumentName }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The instrument identity form is no longer available.');

        return;
    }

    let saving = false;
    const result = await Swal.fire({
        icon: 'question',
        title: 'Save Instrument Identity Changes?',
        html: `<p class="instruments-desk-swal-text">Are you sure you want to save these identity changes?</p><p class="instruments-desk-swal-text"><strong>Instrument: ${escapeHtml(instrumentName ?? '')}</strong></p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, Save Changes',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            saving = true;

            try {
                const response = await component.call('saveIdentity');

                if (!response?.saved) {
                    saving = false;

                    if (response?.validation) {
                        Swal.close();

                        return undefined;
                    }

                    Swal.showValidationMessage(response?.message ?? 'The instrument identity could not be saved. Please try again.');

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.showValidationMessage('The instrument identity could not be saved. Please try again.');

                return undefined;
            }
        },
    });

    if (result.isConfirmed) {
        toast.fire({
            icon: 'success',
            title: 'Instrument Identity Updated!',
            text: 'The instrument identity has been updated successfully.',
        });
    }
}

async function confirmIdentityDiscard({ componentId }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        return;
    }

    const fields = document.querySelectorAll(`[data-component-id="${CSS.escape(componentId)}"] [data-identity-field]`);
    const hasChanges = [...fields].some((field) => field.value !== (field.dataset.initialValue ?? ''));

    if (!hasChanges) {
        await component.call('cancelEditingIdentity');

        return;
    }

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Discard Changes?',
        text: 'Your unsaved identity changes will be lost.',
        showCancelButton: true,
        confirmButtonText: 'Discard Changes',
        cancelButtonText: 'Keep Editing',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (result.isConfirmed) {
        await component.call('cancelEditingIdentity');
    }
}

async function confirmStandardDiscard({ componentId }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The standard form is no longer available.');

        return;
    }

    const fieldIds = ['assign-new-name', 'assign-new-type', 'assign-new-serial'];
    const hasChanges = fieldIds.some((id) => document.getElementById(id)?.value.trim() !== '');

    if (!hasChanges) {
        await component.call('cancelAddingStandard');

        return;
    }

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Discard Changes?',
        text: 'Your unsaved standard information will be lost.',
        showCancelButton: true,
        confirmButtonText: 'Discard Changes',
        cancelButtonText: 'Keep Editing',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (result.isConfirmed) {
        await component.call('cancelAddingStandard');
    }
}

async function confirmTemplateRecovery({ componentId, scopeId, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.call('recoverTemplateValues', Number(scopeId));
}

async function confirmTestResultClear({ componentId, calibrationScopeId, testPointId, columnKey, title, text, confirmText, cancelText }) {
    const result = await Swal.fire({
        icon: 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
    });

    if (!result.isConfirmed || typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The calibration session is no longer available.');

        return;
    }

    await component.call('saveTestResult', Number(calibrationScopeId), Number(testPointId), columnKey);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function validationMessage(errors) {
    const messages = Object.values(errors ?? {}).flat().filter(Boolean);

    if (messages.length === 0) {
        return 'The test table could not be created. Check the values and try again.';
    }

    return messages.map((message) => escapeHtml(message)).join('<br>');
}

async function confirmTestTablePublish({ componentId, profileId, profileName, version }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let publishing = false;

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Publish Test Table Version?',
        html: `
            <div class="instruments-desk-swal-publish-content">
                <p class="instruments-desk-swal-publish-question">
                    Are you sure you want to publish this draft as the active version?
                </p>

                <dl class="instruments-desk-swal-publish-details">
                    <div>
                        <dt>Test Table</dt>
                        <dd>${escapeHtml(profileName ?? 'Selected test table')}</dd>
                    </div>
                    <div>
                        <dt>Version</dt>
                        <dd>${escapeHtml(version ?? 'Draft')}</dd>
                    </div>
                </dl>

                <p class="instruments-desk-swal-publish-warning">
                    Once published, this version becomes read-only. Further changes require creating a new draft version.
                </p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Publish Version',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal instruments-desk-swal-publish',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-publish-container',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (publishing) {
                return false;
            }

            publishing = true;

            try {
                const response = await component.call('publishVersion', Number(profileId));

                if (!response?.published) {
                    publishing = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return false;
                }

                return true;
            } catch (error) {
                publishing = false;
                Swal.showValidationMessage(error?.message ?? 'The version could not be published.');

                return false;
            }
        },
    });

    if (result.isConfirmed) {
        notify('success', 'Version Published Successfully!');
    }
}

async function confirmTestTableFork({ componentId, profileId, profileName, version }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let forking = false;

    const result = await Swal.fire({
        icon: 'question',
        title: 'Create Draft Version?',
        html: `
            <div class="instruments-desk-swal-text">
                <p>Create a new draft from the active version of <strong>${escapeHtml(profileName ?? 'this test table')}</strong>?</p>
                <p>The current ${escapeHtml(version ?? 'active')} version remains unchanged.</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Fork Draft',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (forking) {
                return false;
            }

            forking = true;

            try {
                await component.call('forkDraft', Number(profileId));

                return true;
            } catch (error) {
                forking = false;
                Swal.showValidationMessage(error?.message ?? 'The draft could not be created.');

                return false;
            }
        },
    });

    if (result.isConfirmed) {
        notify('success', 'Draft created.');
    }
}

async function confirmTestTableArchive({ componentId, profileId, profileName }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let archiving = false;

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Archive Test Table?',
        html: `
            <div class="instruments-desk-swal-text">
                <p>Archive <strong>${escapeHtml(profileName ?? 'this test table')}</strong>?</p>
                <p>Historical calibrations stay unchanged. The table will become read-only and no longer appear among active test tables.</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Archive Table',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (archiving) {
                return false;
            }

            archiving = true;

            try {
                await component.call('archiveTestTable', Number(profileId));

                return true;
            } catch (error) {
                archiving = false;
                Swal.showValidationMessage(error?.message ?? 'The test table could not be archived.');

                return false;
            }
        },
    });

    if (result.isConfirmed) {
        notify('success', 'Test table archived.');
    }
}

async function confirmTestTableRestore({ componentId, profileId, profileName }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let restoring = false;

    const result = await Swal.fire({
        icon: 'question',
        title: 'Restore Test Table?',
        html: `
            <div class="instruments-desk-swal-text">
                <p>Restore <strong>${escapeHtml(profileName ?? 'this test table')}</strong> to the active test tables?</p>
                <p>Its existing versions, columns, rows, and formulas will be preserved.</p>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Yes, Restore Table',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: () => !Swal.isLoading(),
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (restoring) {
                return false;
            }

            restoring = true;

            try {
                const response = await component.call('restoreTestTable', Number(profileId));

                if (!response?.restored) {
                    restoring = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return false;
                }

                return true;
            } catch (error) {
                restoring = false;
                Swal.showValidationMessage(error?.message ?? 'The test table could not be restored.');

                return false;
            }
        },
    });

    if (result.isConfirmed) {
        notify('success', 'Test table restored.');
    }
}

async function openTestTableModal({ componentId, tableTitleRequired = false }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    const titleRequired = Boolean(tableTitleRequired);
    let saving = false;
    const titleHint = titleRequired ? '' : '<span class="font-normal text-muted">(optional)</span>';

    await Swal.fire({
        title: 'Add Test Table',
        html: `
            <div class="instruments-desk-swal-form">
                <label class="instruments-desk-swal-field">
                    <span>Profile Name <strong aria-hidden="true">*</strong></span>
                    <input id="swal-profile-name" class="swal2-input instruments-desk-swal-input" maxlength="150" required autofocus>
                </label>
                <label class="instruments-desk-swal-field">
                    <span>Display Title ${titleHint}</span>
                    <input id="swal-display-title" class="swal2-input instruments-desk-swal-input" maxlength="255" ${titleRequired ? 'required' : ''}>
                    <small class="instruments-desk-swal-help">Optional label shown with this test table.</small>
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Create Table',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusConfirm: false,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            const profileName = document.getElementById('swal-profile-name')?.value ?? '';
            const displayTitle = document.getElementById('swal-display-title')?.value ?? '';

            if (!profileName.trim()) {
                Swal.showValidationMessage('Enter a profile name.');

                return undefined;
            }

            if (titleRequired && !displayTitle.trim()) {
                Swal.showValidationMessage('Enter a table title.');

                return undefined;
            }

            saving = true;
            Swal.showLoading();

            try {
                await component.set('profileName', profileName);
                await component.set('displayTitle', displayTitle);

                const response = await component.call('saveTestTableFromModal');

                if (!response?.saved) {
                    saving = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The test table could not be created. Check the values and try again.');

                return undefined;
            }
        },
        didOpen: () => {
            document.getElementById('swal-profile-name')?.focus();
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Test table added.');

            return;
        }

        component.call('cancelAddingTestTable');
    });
}

async function openTestTableEditModal({ componentId, profileId, profileName, displayTitle, testTableRemarks, tableTitleRequired }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    const titleRequired = Boolean(tableTitleRequired);
    let saving = false;

    await Swal.fire({
        title: 'Edit Test Table',
        html: `
            <div class="instruments-desk-swal-form">
                <label class="instruments-desk-swal-field">
                    <span>Profile Name <strong aria-hidden="true">*</strong></span>
                    <input id="swal-edit-profile-name" class="swal2-input instruments-desk-swal-input" maxlength="150" value="${escapeHtml(profileName ?? '')}" required autofocus>
                </label>
                <label class="instruments-desk-swal-field">
                    <span>Remarks <span class="font-normal text-muted">(optional)</span></span>
                    <textarea id="swal-edit-test-table-remarks" class="swal2-textarea instruments-desk-swal-input" maxlength="5000">${escapeHtml(testTableRemarks ?? '')}</textarea>
                </label>
                <label class="instruments-desk-swal-field">
                    <span>Table Title ${titleRequired ? '<strong aria-hidden="true">*</strong>' : '<span class="font-normal text-muted">(optional)</span>'}</span>
                    <input id="swal-edit-display-title" class="swal2-input instruments-desk-swal-input" maxlength="255" value="${escapeHtml(displayTitle ?? '')}" ${titleRequired ? 'required' : ''}>
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusConfirm: false,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            const nextProfileName = document.getElementById('swal-edit-profile-name')?.value ?? '';
            const nextDisplayTitle = document.getElementById('swal-edit-display-title')?.value ?? '';
            const nextRemarks = document.getElementById('swal-edit-test-table-remarks')?.value ?? '';

            if (!nextProfileName.trim()) {
                Swal.showValidationMessage('Enter a profile name.');

                return undefined;
            }

            if (titleRequired && !nextDisplayTitle.trim()) {
                Swal.showValidationMessage('Enter a table title.');

                return undefined;
            }

            saving = true;
            Swal.showLoading();

            try {
                await component.set('profileName', nextProfileName);
                await component.set('displayTitle', nextDisplayTitle);
                await component.set('testTableRemarks', nextRemarks);

                const response = await component.call('saveEditedTestTableFromModal', Number(profileId));

                if (!response?.saved) {
                    saving = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The test table could not be updated. Check the values and try again.');

                return undefined;
            }
        },
        didOpen: () => {
            document.getElementById('swal-edit-profile-name')?.focus();
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Test table updated.');

            return;
        }

        component.call('cancelEditingTestTable');
    });
}

async function openTestTableDeleteModal({ componentId, profileId, profileName }) {
    if (typeof componentId !== 'string' || !Number.isInteger(Number(profileId)) || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let deleting = false;

    await Swal.fire({
        icon: 'warning',
        title: 'Delete Test Table?',
        html: `<p class="instruments-desk-swal-text">Delete <strong>${escapeHtml(profileName ?? 'this test table')}</strong>? This action cannot be undone.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Delete Table',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (deleting) {
                return undefined;
            }

            deleting = true;
            Swal.showLoading();

            try {
                const response = await component.call('deleteTestTableFromModal', Number(profileId));

                if (!response?.deleted) {
                    deleting = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                deleting = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The test table could not be deleted. Check its usage and try again.');

                return undefined;
            }
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Test table deleted.');

            return;
        }

        component.call('cancelDeletingTestTable');
    });
}

async function openTestRowEditModal({ componentId, columns = {}, values = {} }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test row editor is no longer available.');

        return;
    }

    const columnList = Array.isArray(columns) ? columns : Object.values(columns);
    const templateColumns = columnList.filter((column) => column.valueSource === 'template');
    let saving = false;
    const fields = columnList.map((column) => {
        if (column.valueSource !== 'template') {
            return `
                <div class="instruments-desk-swal-field">
                    <span>${escapeHtml(column.label)}</span>
                    <p class="instruments-desk-swal-readonly">Filled during calibration</p>
                </div>
            `;
        }

        const inputMode = column.dataType === 'numeric' ? ' inputmode="decimal"' : '';
        const placeholder = column.dataType === 'duration' ? ' placeholder="hh:mm:ss"' : '';
        const unit = column.unit ? ` <span class="font-normal text-muted">(${escapeHtml(column.unit)})</span>` : '';
        const currentValue = values?.[String(column.id)] ?? '';

        return `
            <label class="instruments-desk-swal-field">
                <span>${escapeHtml(column.label)}${unit}</span>
                <input id="swal-test-row-${Number(column.id)}" data-test-row-value="${Number(column.id)}" class="swal2-input instruments-desk-swal-input" value="${escapeHtml(currentValue)}"${inputMode}${placeholder}>
            </label>
        `;
    }).join('');

    await Swal.fire({
        title: 'Edit Test Row',
        html: `
            <div class="instruments-desk-swal-form">
                <p class="instruments-desk-swal-text">Template values can be filled or left blank. Engineer and calculated values are entered elsewhere.</p>
                ${fields}
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Row',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusConfirm: false,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (saving) {
                return undefined;
            }

            const nextValues = {};
            templateColumns.forEach((column) => {
                nextValues[String(column.id)] = document.getElementById(`swal-test-row-${Number(column.id)}`)?.value ?? '';
            });
            saving = true;
            Swal.showLoading();

            try {
                await component.set('templateValues', nextValues);
                const response = await component.call('saveTestRowFromModal');

                if (!response?.saved) {
                    saving = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                saving = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The test row could not be saved. Check the values and try again.');

                return undefined;
            }
        },
        didOpen: () => {
            document.querySelector('[data-test-row-value]')?.focus();
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Test row saved.');

            return;
        }

        component.call('cancelEditingRow');
    });
}

async function confirmTestRowRemoval({ componentId, profileId, pointId }) {
    if (typeof componentId !== 'string' || Swal.isVisible()) {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test row editor is no longer available.');

        return;
    }

    let deleting = false;

    await Swal.fire({
        icon: 'warning',
        title: 'Delete this row?',
        text: 'This action cannot be undone.',
        showCancelButton: true,
        confirmButtonText: 'Delete Row',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (deleting) {
                return undefined;
            }

            deleting = true;
            Swal.showLoading();

            try {
                const response = await component.call(
                    'deleteTestRowFromModal',
                    Number(profileId),
                    Number(pointId),
                );

                if (!response?.deleted) {
                    deleting = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                deleting = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The test row could not be deleted.');

                return undefined;
            }
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Test row deleted.');

            return;
        }

        component.call('cancelDeletingTestRow');
    });
}

async function confirmTestColumnRemoval({ componentId, profileId, columnId, columnLabel }) {
    if (typeof componentId !== 'string') {
        return;
    }

    const component = window.Livewire?.find(componentId);

    if (!component) {
        notify('error', 'The test table editor is no longer available.');

        return;
    }

    let deleting = false;

    await Swal.fire({
        icon: 'warning',
        title: 'Delete Column?',
        html: `<p class="instruments-desk-swal-text">Delete <strong>${escapeHtml(columnLabel ?? 'this column')}</strong>? Existing point values for this column will also be removed.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Delete Column',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'instruments-desk-swal-modal',
            title: 'instruments-desk-swal-title',
            htmlContainer: 'instruments-desk-swal-text',
            confirmButton: 'instruments-desk-swal-confirm',
            cancelButton: 'instruments-desk-swal-cancel',
        },
        preConfirm: async () => {
            if (deleting) {
                return undefined;
            }

            deleting = true;
            Swal.showLoading();

            try {
                const response = await component.call('deleteTestColumnFromModal');

                if (!response?.deleted) {
                    deleting = false;
                    Swal.hideLoading();
                    Swal.showValidationMessage(validationMessage(response?.errors));

                    return undefined;
                }

                return response;
            } catch (error) {
                deleting = false;
                Swal.hideLoading();
                Swal.showValidationMessage('The column could not be deleted. Check whether it is used by a calculated column.');

                return undefined;
            }
        },
    }).then((result) => {
        if (result.isConfirmed) {
            notify('success', 'Column deleted.');

            return;
        }

        component.call('cancelDeletingTestColumn');
    });
}

function registerColumnDragAndDrop() {
    if (window.__instrumentsDeskColumnDragRegistered) {
        return;
    }

    window.__instrumentsDeskColumnDragRegistered = true;
    let draggedItem = null;
    let draggedList = null;
    let draggedOriginalOrder = null;
    let dropTarget = null;

    const clearDropTarget = () => {
        dropTarget?.classList.remove('ring-2', 'ring-brand', 'ring-offset-2');
        dropTarget = null;
    };

    document.addEventListener('dragstart', (event) => {
        const item = event.target.closest('[data-column-sort-item]');

        if (!item) {
            return;
        }

        draggedItem = item;
        draggedList = item.closest('[data-column-sort-list]');
        draggedOriginalOrder = draggedList
            ? [...draggedList.querySelectorAll('[data-column-sort-item]')].map((column) => Number(column.dataset.columnId))
            : null;
        item.classList.add('opacity-50');
        item.setAttribute('aria-grabbed', 'true');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.columnId ?? '');
    });

    document.addEventListener('dragend', () => {
        clearDropTarget();
        draggedItem?.classList.remove('opacity-50');
        draggedItem?.setAttribute('aria-grabbed', 'false');
        draggedItem = null;
        draggedList = null;
        draggedOriginalOrder = null;
    });

    document.addEventListener('dragover', (event) => {
        const list = event.target.closest('[data-column-sort-list]');
        const target = event.target.closest('[data-column-sort-item]');

        if (!list || !draggedItem || !list.contains(draggedItem)) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (!target || target === draggedItem) {
            clearDropTarget();

            return;
        }

        if (dropTarget !== target) {
            clearDropTarget();
            dropTarget = target;
            dropTarget.classList.add('ring-2', 'ring-brand', 'ring-offset-2');
        }

        const midpoint = target.getBoundingClientRect().top + (target.offsetHeight / 2);

        if (event.clientY < midpoint) {
            target.before(draggedItem);
        } else {
            target.after(draggedItem);
        }
    });

    document.addEventListener('drop', async (event) => {
        const list = event.target.closest('[data-column-sort-list]');

        if (!list || !draggedItem || list.dataset.reordering === 'true') {
            return;
        }

        event.preventDefault();
        clearDropTarget();
        list.dataset.reordering = 'true';

        const component = window.Livewire?.find(list.dataset.componentId);
        const profileId = Number(list.dataset.profileId);
        const items = [...list.querySelectorAll('[data-column-sort-item]')];
        const currentOrder = items.map((item) => Number(item.dataset.columnId));
        const originalOrder = draggedOriginalOrder ?? currentOrder;

        if (!component || !Number.isInteger(profileId) || currentOrder.length < 1) {
            list.dataset.reordering = 'false';

            return;
        }

        try {
            await component.call('reorderTestColumns', profileId, currentOrder);
            notify('success', 'Column order updated.');
        } catch (error) {
            const itemMap = new Map(items.map((item) => [Number(item.dataset.columnId), item]));

            originalOrder.forEach((columnId) => {
                const item = itemMap.get(columnId);

                if (item) {
                    list.append(item);
                }
            });

            notify('error', 'The column order could not be saved. Refresh the page and try again.');
        } finally {
            list.dataset.reordering = 'false';
        }
    });
}

function registerLivewireNotifications() {
    if (window.__instrumentsDeskNotificationsRegistered || !window.Livewire) {
        return;
    }

    window.__instrumentsDeskNotificationsRegistered = true;

    window.Livewire.on('instruments-desk-notification', ({ type, message }) => {
        if (pendingExternalPdfUpload !== null && type === 'error') {
            resolveExternalPdfUpload({ success: false, message });

            return;
        }

        if (pendingExternalPdfRemoval !== null && type === 'error') {
            resolveExternalPdfRemoval({ success: false, message });

            return;
        }

        if ((pendingExternalPdfUpload !== null || pendingExternalPdfRemoval !== null) && type === 'success') {
            return;
        }

        notify(type, message);
    });

    window.Livewire.on('instruments-desk-terminal-success', (payload) => {
        showTerminalSuccess(payload);
    });

    window.Livewire.on('instruments-desk-confirm-cancellation', (payload) => {
        confirmCancellation(payload);
    });

    window.Livewire.on('instruments-desk-confirm-start-calibration', (payload) => {
        confirmStartCalibration(payload);
    });

    window.Livewire.on('instruments-desk-confirm-completion', (payload) => {
        confirmCompletion(payload);
    });

    window.Livewire.on('instruments-desk-confirm-activity-removal', (payload) => {
        confirmActivityRemoval(payload);
    });

    window.Livewire.on('instruments-desk-confirm-standard-removal', (payload) => {
        confirmStandardRemoval(payload);
    });

    window.Livewire.on('instruments-desk-confirm-new-standard-creation', (payload) => {
        confirmNewStandardCreation(payload);
    });

    window.Livewire.on('instruments-desk-confirm-standard-master-save', (payload) => {
        confirmStandardMasterSave(payload);
    });

    window.Livewire.on('instruments-desk-confirm-standard-master-delete', (payload) => {
        confirmStandardMasterDelete(payload);
    });

    window.Livewire.on('instruments-desk-confirm-activity-master-save', (payload) => {
        confirmActivityMasterSave(payload);
    });

    window.Livewire.on('instruments-desk-confirm-activity-deactivate', (payload) => {
        confirmActivityMasterStatus({ ...payload, activate: false });
    });

    window.Livewire.on('instruments-desk-confirm-activity-reactivate', (payload) => {
        confirmActivityMasterStatus({ ...payload, activate: true });
    });

    window.Livewire.on('instruments-desk-confirm-activity-delete', (payload) => {
        confirmActivityMasterDelete(payload);
    });

    window.Livewire.on('instruments-desk-confirm-identity-update', (payload) => {
        confirmIdentityUpdate(payload);
    });

    window.Livewire.on('instruments-desk-confirm-standard-assignment', (payload) => {
        confirmStandardAssignment(payload);
    });

    window.Livewire.on('instruments-desk-confirm-template-recovery', (payload) => {
        confirmTemplateRecovery(payload);
    });

    window.Livewire.on('instruments-desk-confirm-test-result-clear', (payload) => {
        confirmTestResultClear(payload);
    });

    window.Livewire.on('instruments-desk-confirm-test-table-publish', (payload) => {
        confirmTestTablePublish(payload);
    });

    window.Livewire.on('instruments-desk-confirm-external-pdf-upload', (payload) => {
        confirmExternalPdfUpload(payload);
    });

    window.Livewire.on('instruments-desk-external-pdf-uploaded', ({ componentId }) => {
        resolveExternalPdfUpload({ success: true });

        if (typeof componentId !== 'string') {
            return;
        }

        const root = document.querySelector(`[data-component-id="${CSS.escape(componentId)}"]`);
        const form = root?.querySelector('[data-external-pdf-form]');

        if (form) {
            clearExternalPdfSelection(form, { syncComponent: false });
        }
    });

    window.Livewire.on('instruments-desk-external-pdf-removed', () => {
        resolveExternalPdfRemoval({ success: true });
    });

    window.Livewire.on('instruments-desk-open-test-table-modal', (payload) => {
        openTestTableModal(payload);
    });

    window.Livewire.on('instruments-desk-open-test-table-edit-modal', (payload) => {
        openTestTableEditModal(payload);
    });

    window.Livewire.on('instruments-desk-open-test-table-delete-modal', (payload) => {
        openTestTableDeleteModal(payload);
    });

    window.Livewire.on('instruments-desk-open-test-row-edit-modal', (payload) => {
        openTestRowEditModal(payload);
    });

    window.Livewire.on('instruments-desk-confirm-test-row-removal', (payload) => {
        confirmTestRowRemoval(payload);
    });

    window.Livewire.on('instruments-desk-confirm-test-column-removal', (payload) => {
        confirmTestColumnRemoval(payload);
    });

    registerColumnDragAndDrop();
}

function registerMobileNavigation() {
    document.querySelectorAll('[data-mobile-nav]').forEach((navigation) => {
        if (navigation.dataset.mobileNavRegistered === 'true') {
            return;
        }

        const toggle = navigation.querySelector('.mobile-nav-toggle');
        const panel = navigation.querySelector('.mobile-nav-panel');

        if (!toggle || !panel) {
            return;
        }

        navigation.dataset.mobileNavRegistered = 'true';

        const close = ({ restoreFocus = false } = {}) => {
            navigation.removeAttribute('data-mobile-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            panel.setAttribute('aria-hidden', 'true');

            if (restoreFocus) {
                toggle.focus();
            }
        };

        toggle.addEventListener('click', () => {
            const isOpen = navigation.hasAttribute('data-mobile-nav-open');

            if (isOpen) {
                close();
                return;
            }

            navigation.setAttribute('data-mobile-nav-open', '');
            toggle.setAttribute('aria-expanded', 'true');
            panel.setAttribute('aria-hidden', 'false');
        });

        navigation.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => close());
        });

        navigation.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navigation.hasAttribute('data-mobile-nav-open')) {
                event.preventDefault();
                close({ restoreFocus: true });
            }
        });
    });
}

function registerUserStatusConfirmation() {
    if (window.__instrumentsDeskUserStatusConfirmationRegistered) {
        return;
    }

    window.__instrumentsDeskUserStatusConfirmationRegistered = true;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-user-status]');

        if (!button) {
            return;
        }

        event.preventDefault();
        const root = button.closest('[data-component-id]');

        void confirmUserStatusChange({
            componentId: root?.dataset.componentId,
            userId: button.dataset.userId,
            userName: button.dataset.userName,
            activate: button.dataset.userAction === 'activate',
        });
    });
}

function registerCertificateRefreshConfirmation() {
    if (window.__instrumentsDeskCertificateRefreshRegistered) {
        return;
    }

    window.__instrumentsDeskCertificateRefreshRegistered = true;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-certificate-refresh-confirm]');

        if (!button) {
            return;
        }

        event.preventDefault();
        void confirmCertificateRefresh(
            button.closest('form'),
            button.dataset.confirmMessage ?? 'The current PDF will remain available in revision history.',
        );
    });
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

function synchronizeTopLevelPageScrollPosition() {
    const shouldResetScroll = document.querySelector('[data-dashboard-page], [data-instruments-page], [data-calibrations-page]') !== null;

    if ('scrollRestoration' in history) {
        history.scrollRestoration = shouldResetScroll ? 'manual' : 'auto';
    }

    if (!shouldResetScroll) {
        return;
    }

    document.scrollingElement?.scrollTo({
        top: 0,
        left: 0,
        behavior: 'auto',
    });
}

function setLoginLoaderVisibility(loader, visible) {
    if (visible) {
        loader.classList.remove('hidden');
        loader.setAttribute('aria-hidden', 'false');

        return;
    }

    loader.classList.add('hidden');
    loader.setAttribute('aria-hidden', 'true');
}

function runLoginLogoAssembly(loader, { hideAfter = true } = {}) {
    const layers = [...loader.querySelectorAll('[data-logo-layer]')];

    if (layers.length !== 3) {
        return;
    }

    const top = loader.querySelector('[data-logo-layer="top"]');
    const middle = loader.querySelector('[data-logo-layer="middle"]');
    const bottom = loader.querySelector('[data-logo-layer="bottom"]');

    gsap.killTweensOf([loader, ...layers]);
    setLoginLoaderVisibility(loader, true);
    gsap.set(loader, { autoAlpha: 1 });
    gsap.set(layers, {
        opacity: 1,
        rotation: 0,
        scale: 1,
        x: 0,
        y: 0,
        transformOrigin: '50% 50%',
    });
    gsap.set(top, { rotation: -8, x: 23, y: -24, scale: 0.9 });
    gsap.set(middle, { rotation: 6, x: -19, y: 14, scale: 0.94 });
    gsap.set(bottom, { rotation: 8, x: 20, y: 27, scale: 0.92 });

    const timeline = gsap.timeline({ defaults: { ease: 'power3.out' } });

    timeline.to(layers, {
        duration: 0.74,
        rotation: 0,
        scale: 1,
        x: 0,
        y: 0,
        stagger: 0.08,
    }).to(loader.querySelector('[data-login-logo]'), {
        duration: 0.35,
        ease: 'sine.inOut',
        scale: 1.04,
    }).to(loader.querySelector('[data-login-logo]'), {
        duration: 0.35,
        ease: 'sine.inOut',
        scale: 1,
    }).to({}, {
        duration: 0.5,
    });

    if (hideAfter) {
        timeline.to(loader, {
            autoAlpha: 0,
            duration: 0.4,
            ease: 'power2.out',
            onComplete: () => setLoginLoaderVisibility(loader, false),
        });

        window.setTimeout(() => {
            if (!loader.classList.contains('hidden')) {
                setLoginLoaderVisibility(loader, false);
            }
        }, 2700);
    }
}

function initializeLoginAnimations() {
    const page = document.querySelector('[data-login-page]');
    const loader = page?.querySelector('[data-login-loader]');

    if (!page || !loader || page.dataset.animationsInitialized === 'true') {
        return;
    }

    page.dataset.animationsInitialized = 'true';

    const illustration = page.querySelector('[data-login-illustration]');
    const mobileBrand = page.querySelector('[data-login-mobile-brand]');
    const formContent = page.querySelector('[data-login-form-content]');
    const heading = page.querySelector('[data-login-heading]');
    const form = page.querySelector('[data-login-form]');
    const footer = formContent?.querySelector('p.mt-8');
    const reducedMotion = prefersReducedMotion();

    form?.addEventListener('submit', () => {
        if (form.dataset.submitting === 'true') {
            return;
        }

        form.dataset.submitting = 'true';

        if (reducedMotion) {
            setLoginLoaderVisibility(loader, true);

            return;
        }

        runLoginLogoAssembly(loader, { hideAfter: false });
    });

    if (reducedMotion) {
        setLoginLoaderVisibility(loader, false);

        return;
    }

    runLoginLogoAssembly(loader);

    if (window.matchMedia('(min-width: 1280px)').matches) {
        gsap.from(illustration, {
            duration: 0.68,
            ease: 'power3.out',
            opacity: 0,
            y: 20,
            delay: 2.5,
        });
        gsap.from(formContent, {
            duration: 0.68,
            ease: 'power3.out',
            opacity: 0,
            x: 20,
            delay: 2.62,
        });

        return;
    }

    gsap.from(mobileBrand, {
        duration: 0.52,
        ease: 'power3.out',
        opacity: 0,
        y: -14,
        delay: 2.5,
    });
    gsap.from(heading, {
        duration: 0.52,
        ease: 'power3.out',
        opacity: 0,
        y: 16,
        delay: 2.62,
    });
    gsap.from(form, {
        duration: 0.52,
        ease: 'power3.out',
        opacity: 0,
        y: 16,
        delay: 2.72,
    });
    gsap.from(footer, {
        duration: 0.52,
        ease: 'power3.out',
        opacity: 0,
        y: 12,
        delay: 2.82,
    });

}

function dashboardAnimationTargets(page) {
    const header = page.querySelector('[data-dashboard-header]');
    const stats = [...page.querySelectorAll('[data-dashboard-stat]')];
    const content = [...page.querySelectorAll('[data-dashboard-content]')];

    return [
        header,
        ...stats,
        ...content,
        ...page.querySelectorAll('[data-dashboard-counter], [data-dashboard-percentage], [data-dashboard-chart-segment]'),
    ].filter(Boolean);
}

function dashboardCounterValue(counter) {
    const value = Number.parseInt(counter.dataset.dashboardCounter ?? '0', 10);

    return Number.isFinite(value) ? value : 0;
}

function restoreDashboardCounterValues(page) {
    page.querySelectorAll('[data-dashboard-counter]').forEach((counter) => {
        counter.textContent = String(dashboardCounterValue(counter));
    });
}

function dashboardPercentageValue(percentage) {
    const value = Number.parseFloat(percentage.dataset.dashboardPercentage ?? '0');

    return Number.isFinite(value) ? value : 0;
}

function restoreDashboardPercentageValues(page) {
    page.querySelectorAll('[data-dashboard-percentage]').forEach((percentage) => {
        percentage.textContent = `${dashboardPercentageValue(percentage).toFixed(1)}%`;
    });
}

function dashboardDistributionStart(element) {
    const row = element.closest('[data-dashboard-distribution-row]');
    const index = Number.parseInt(row?.dataset.dashboardDistributionRow ?? '0', 10);

    return 0.2 + (Number.isFinite(index) ? index * 0.07 : 0);
}

function restoreDashboardChartSegments(page) {
    page.querySelectorAll('[data-dashboard-chart-segment]').forEach((segment) => {
        const length = Number.parseFloat(segment.dataset.segmentLength ?? '0');
        const remainder = Math.max(100 - length, 0);

        segment.style.strokeDasharray = `${length} ${remainder}`;
    });
}

function restoreDashboardAnimationState(page) {
    const targets = dashboardAnimationTargets(page);
    const stats = [...page.querySelectorAll('[data-dashboard-stat]')];

    page._dashboardAnimationTimeline?.kill();
    page._dashboardAnimationTimeline = null;
    page._dashboardCounterStates = null;
    page._dashboardPercentageStates = null;
    page._dashboardSegmentStates = null;
    gsap.killTweensOf(targets);
    gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
    gsap.set(stats, { clearProps: 'transition' });
    restoreDashboardCounterValues(page);
    restoreDashboardPercentageValues(page);
    restoreDashboardChartSegments(page);
    document.documentElement.classList.remove('dashboard-animation-pending');
}

function instrumentAnimationTargets(page) {
    return [
        page.querySelector('[data-instruments-header]'),
        ...page.querySelectorAll('[data-instruments-stat]'),
        page.querySelector('[data-instruments-panel]'),
        page.querySelector('[data-instruments-toolbar]'),
        ...page.querySelectorAll('[data-instruments-counter]'),
    ].filter(Boolean);
}

function instrumentCounterValue(counter) {
    const value = Number.parseInt(counter.dataset.instrumentsCounter ?? '0', 10);

    return Number.isFinite(value) ? value : 0;
}

function restoreInstrumentAnimationState(page) {
    const targets = instrumentAnimationTargets(page);
    const stats = [...page.querySelectorAll('[data-instruments-stat]')];

    page._instrumentsAnimationTimeline?.kill();
    page._instrumentsAnimationTimeline = null;
    page._instrumentsCounterStates = null;
    gsap.killTweensOf(targets);
    gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
    gsap.set(stats, { clearProps: 'transition' });

    page.querySelectorAll('[data-instruments-counter]').forEach((counter) => {
        counter.textContent = String(instrumentCounterValue(counter));
    });

    document.documentElement.classList.remove('instruments-animation-pending');
}

function initializeInstrumentsAnimations() {
    const page = document.querySelector('[data-instruments-page]');

    if (!page || page.dataset.animationsInitialized === 'true') {
        return;
    }

    page.dataset.animationsInitialized = 'true';

    if (!document.documentElement.classList.contains('instruments-animation-pending')) {
        restoreInstrumentAnimationState(page);

        return;
    }

    const header = page.querySelector('[data-instruments-header]');
    const stats = [...page.querySelectorAll('[data-instruments-stat]')];
    const panel = page.querySelector('[data-instruments-panel]');
    const toolbar = page.querySelector('[data-instruments-toolbar]');
    const counters = [...page.querySelectorAll('[data-instruments-counter]')];
    const targets = instrumentAnimationTargets(page);

    if (!header || stats.length === 0 || !panel || !toolbar) {
        restoreInstrumentAnimationState(page);

        return;
    }

    if (prefersReducedMotion()) {
        restoreInstrumentAnimationState(page);

        return;
    }

    try {
        const counterStates = counters.map((counter) => ({
            element: counter,
            state: { value: 0 },
            target: instrumentCounterValue(counter),
        }));

        page._instrumentsCounterStates = counterStates;

        gsap.set(header, { opacity: 0, y: 18 });
        gsap.set(stats, { opacity: 0, y: 24, transition: 'none' });
        gsap.set(panel, { opacity: 0, y: 18 });
        gsap.set(toolbar, { opacity: 0, x: 12 });

        counterStates.forEach(({ element }) => {
            element.textContent = '0';
        });

        const timeline = gsap.timeline({
            paused: true,
            defaults: { ease: 'power3.out' },
            onComplete: () => {
                gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
                gsap.set(stats, { clearProps: 'transition' });
                counterStates.forEach(({ element, target }) => {
                    element.textContent = String(target);
                });
                page._instrumentsCounterStates = null;
                page._instrumentsAnimationTimeline = null;
            },
        });

        page._instrumentsAnimationTimeline = timeline;
        timeline.to(header, {
            duration: 0.65,
            opacity: 1,
            y: 0,
        }, 0).to(stats, {
            duration: 0.75,
            opacity: 1,
            y: 0,
            stagger: 0.08,
        }, 0.12).to(panel, {
            duration: 0.85,
            opacity: 1,
            y: 0,
        }, 0.24).to(toolbar, {
            duration: 0.6,
            opacity: 1,
            x: 0,
        }, 0.32);

        counterStates.forEach(({ element, state, target }, index) => {
            timeline.to(state, {
                duration: 1.35,
                value: target,
                ease: 'power2.out',
                snap: { value: 1 },
                onUpdate: () => {
                    element.textContent = String(Math.round(state.value));
                },
            }, 0.12 + (index * 0.08));
        });

        document.documentElement.classList.remove('instruments-animation-pending');
        timeline.play(0);
    } catch {
        restoreInstrumentAnimationState(page);
    }
}

function calibrationAnimationTargets(page) {
    return [
        page.querySelector('[data-calibrations-header]'),
        page.querySelector('[data-calibrations-tabs]'),
        ...page.querySelectorAll('[data-calibrations-stat]'),
        ...page.querySelectorAll('[data-calibrations-content]'),
        page.querySelector('[data-calibrations-toolbar]'),
        ...page.querySelectorAll('[data-calibration-counter], [data-calibration-percentage], [data-calibration-chart-segment]'),
    ].filter(Boolean);
}

function calibrationCounterValue(counter) {
    const value = Number.parseInt(counter.dataset.calibrationCounter ?? '0', 10);

    return Number.isFinite(value) ? value : 0;
}

function calibrationPercentageValue(percentage) {
    const value = Number.parseFloat(percentage.dataset.calibrationPercentage ?? '0');

    return Number.isFinite(value) ? value : 0;
}

function calibrationStatusStart(element) {
    const row = element.closest('[data-calibrations-status-row]');
    const index = Number.parseInt(row?.dataset.calibrationsStatusIndex ?? '0', 10);

    return 0.22 + (Number.isFinite(index) ? index * 0.07 : 0);
}

function restoreCalibrationAnimationState(page) {
    const targets = calibrationAnimationTargets(page);
    const stats = [...page.querySelectorAll('[data-calibrations-stat]')];

    page._calibrationsAnimationTimeline?.kill();
    page._calibrationsAnimationTimeline = null;
    page._calibrationsCounterStates = null;
    page._calibrationsPercentageStates = null;
    page._calibrationsSegmentStates = null;
    gsap.killTweensOf(targets);
    gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
    gsap.set(stats, { clearProps: 'transition' });

    page.querySelectorAll('[data-calibration-counter]').forEach((counter) => {
        counter.textContent = String(calibrationCounterValue(counter));
    });
    page.querySelectorAll('[data-calibration-percentage]').forEach((percentage) => {
        percentage.textContent = `(${percentage.dataset.calibrationPercentageLabel ?? `${calibrationPercentageValue(percentage).toFixed(1)}%`})`;
    });
    page.querySelectorAll('[data-calibration-chart-segment]').forEach((segment) => {
        const length = Number.parseFloat(segment.dataset.segmentLength ?? '0');

        segment.style.strokeDasharray = `${length} ${Math.max(100 - length, 0)}`;
    });

    document.documentElement.classList.remove('calibrations-animation-pending');
}

function initializeCalibrationsAnimations() {
    const page = document.querySelector('[data-calibrations-page]');

    if (!page || page.dataset.animationsInitialized === 'true') {
        return;
    }

    page.dataset.animationsInitialized = 'true';

    if (!document.documentElement.classList.contains('calibrations-animation-pending')) {
        restoreCalibrationAnimationState(page);

        return;
    }

    const header = page.querySelector('[data-calibrations-header]');
    const tabs = page.querySelector('[data-calibrations-tabs]');
    const stats = [...page.querySelectorAll('[data-calibrations-stat]')];
    const content = [...page.querySelectorAll('[data-calibrations-content]')];
    const toolbar = page.querySelector('[data-calibrations-toolbar]');
    const targets = calibrationAnimationTargets(page);

    if (!header || targets.length === 0 || prefersReducedMotion()) {
        restoreCalibrationAnimationState(page);

        return;
    }

    try {
        const counters = [...page.querySelectorAll('[data-calibration-counter]')];
        const counterStates = counters.map((counter) => ({
            element: counter,
            state: { value: 0 },
            target: calibrationCounterValue(counter),
        }));
        const percentageStates = [...page.querySelectorAll('[data-calibration-percentage]')].map((percentage) => ({
            element: percentage,
            state: { value: 0 },
            target: calibrationPercentageValue(percentage),
        }));
        const segmentStates = [...page.querySelectorAll('[data-calibration-chart-segment]')].map((segment) => ({
            element: segment,
            length: Number.parseFloat(segment.dataset.segmentLength ?? '0'),
            state: { progress: 0 },
        }));

        page._calibrationsCounterStates = counterStates;
        page._calibrationsPercentageStates = percentageStates;
        page._calibrationsSegmentStates = segmentStates;

        gsap.set(header, { opacity: 0, y: 18 });
        if (tabs) {
            gsap.set(tabs, { opacity: 0, y: 10 });
        }
        gsap.set(stats, { opacity: 0, y: 24, transition: 'none' });
        gsap.set(content, { opacity: 0, y: 18 });
        if (toolbar) {
            gsap.set(toolbar, { opacity: 0, x: 12 });
        }
        counterStates.forEach(({ element }) => { element.textContent = '0'; });
        percentageStates.forEach(({ element }) => { element.textContent = '(0.0%)'; });
        segmentStates.forEach(({ element }) => { element.style.strokeDasharray = '0 100'; });

        const timeline = gsap.timeline({
            paused: true,
            defaults: { ease: 'power3.out' },
            onComplete: () => {
                gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
                gsap.set(stats, { clearProps: 'transition' });
                counterStates.forEach(({ element, target }) => { element.textContent = String(target); });
                percentageStates.forEach(({ element }) => {
                    element.textContent = `(${element.dataset.calibrationPercentageLabel ?? `${calibrationPercentageValue(element).toFixed(1)}%`})`;
                });
                segmentStates.forEach(({ element, length }) => {
                    element.style.strokeDasharray = `${length} ${Math.max(100 - length, 0)}`;
                });
                page._calibrationsCounterStates = null;
                page._calibrationsPercentageStates = null;
                page._calibrationsSegmentStates = null;
                page._calibrationsAnimationTimeline = null;
            },
        });

        page._calibrationsAnimationTimeline = timeline;
        timeline.to(header, { duration: 0.65, opacity: 1, y: 0 }, 0);
        if (tabs) {
            timeline.to(tabs, { duration: 0.6, opacity: 1, y: 0 }, 0.08);
        }
        if (stats.length > 0) {
            timeline.to(stats, { duration: 0.75, opacity: 1, y: 0, stagger: 0.08 }, 0.12);
        }
        if (content.length > 0) {
            timeline.to(content, { duration: 0.85, opacity: 1, y: 0, ease: 'power2.out' }, 0.24);
        }
        if (toolbar) {
            timeline.to(toolbar, { duration: 0.6, opacity: 1, x: 0 }, 0.36);
        }

        counterStates.forEach(({ element, state, target }) => {
            const card = element.closest('[data-calibrations-stat]');
            const cardIndex = Number.parseInt(card?.dataset.calibrationsStatIndex ?? '0', 10);
            const start = card ? 0.12 + (Number.isFinite(cardIndex) ? cardIndex * 0.08 : 0) : calibrationStatusStart(element);

            timeline.to(state, {
                duration: 1.35,
                value: target,
                ease: 'power2.out',
                snap: { value: 1 },
                onUpdate: () => { element.textContent = String(Math.round(state.value)); },
            }, start);
        });
        percentageStates.forEach(({ element, state, target }) => {
            timeline.to(state, {
                duration: 1.35,
                value: target,
                ease: 'power2.out',
                onUpdate: () => { element.textContent = `(${state.value.toFixed(1)}%)`; },
            }, calibrationStatusStart(element));
        });
        segmentStates.forEach(({ element, length, state }, index) => {
            timeline.to(state, {
                duration: 0.68,
                progress: 1,
                ease: 'power2.out',
                onUpdate: () => {
                    const visibleLength = length * state.progress;
                    element.style.strokeDasharray = `${visibleLength} ${Math.max(100 - visibleLength, 0)}`;
                },
            }, 0.22 + (index * 0.07));
        });

        document.documentElement.classList.remove('calibrations-animation-pending');
        timeline.play(0);
    } catch {
        restoreCalibrationAnimationState(page);
    }
}

function initializeShellAnimations() {
    const root = document.documentElement;

    if (root.dataset.shellAnimationInitialized === 'true') {
        return;
    }

    if (!root.classList.contains('shell-animation-pending')) {
        return;
    }

    const sidebar = document.querySelector('[data-dashboard-sidebar]');
    const header = document.querySelector('[data-mobile-header]');
    const brand = header?.querySelector('[data-mobile-header-brand]');
    const menu = header?.querySelector('[data-mobile-header-menu]');
    const targets = [sidebar, header, brand, menu].filter(Boolean);

    if (!sidebar) {
        root.classList.remove('shell-animation-pending');

        return;
    }

    root.dataset.shellAnimationInitialized = 'true';
    root._shellAnimationTimeline?.kill();
    gsap.killTweensOf(targets);

    if (prefersReducedMotion()) {
        gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
        root.classList.remove('shell-animation-pending');

        return;
    }

    try {
        gsap.set(sidebar, { opacity: 0, x: -28 });
        if (header) {
            gsap.set(header, { opacity: 0, y: -20 });
        }
        if (brand) {
            gsap.set(brand, { opacity: 0, x: -16 });
        }
        if (menu) {
            gsap.set(menu, { opacity: 0, x: 14 });
        }

        const timeline = gsap.timeline({
            paused: true,
            defaults: { ease: 'power3.out' },
            onComplete: () => {
                gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
                root.classList.remove('shell-animation-pending');
                root._shellAnimationTimeline = null;
            },
        });

        root._shellAnimationTimeline = timeline;
        root.classList.remove('shell-animation-pending');
        timeline.to(sidebar, {
            duration: 0.7,
            opacity: 1,
            x: 0,
        }, 0);
        if (header) {
            timeline.to(header, {
                duration: 0.65,
                opacity: 1,
                y: 0,
            }, 0);
        }
        if (brand) {
            timeline.to(brand, {
                duration: 0.65,
                opacity: 1,
                x: 0,
            }, 0.12);
        }
        if (menu) {
            timeline.to(menu, {
                duration: 0.55,
                opacity: 1,
                x: 0,
            }, 0.18);
        }
        timeline.play(0);
    } catch {
        gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
        root.classList.remove('shell-animation-pending');
    }
}

function initializeDashboardAnimations() {
    const page = document.querySelector('[data-dashboard-page]');

    if (!page || page.dataset.animationsInitialized === 'true') {
        return;
    }

    const header = page.querySelector('[data-dashboard-header]');
    const stats = [...page.querySelectorAll('[data-dashboard-stat]')];
    const content = [...page.querySelectorAll('[data-dashboard-content]')];
    const distribution = content[0];
    const recent = content[1];
    const targets = dashboardAnimationTargets(page);
    const animationPending = document.documentElement.classList.contains('dashboard-animation-pending');

    if (!animationPending) {
        restoreDashboardAnimationState(page);
        page.dataset.animationsInitialized = 'true';

        return;
    }

    page._dashboardAnimationTimeline?.kill();
    page._dashboardAnimationTimeline = null;
    gsap.killTweensOf(targets);
    page.dataset.animationsInitialized = 'true';

    if (prefersReducedMotion()) {
        restoreDashboardAnimationState(page);

        return;
    }

    try {
        const counters = [...page.querySelectorAll('[data-dashboard-counter]')];
        const counterStates = counters.map((counter) => ({
            element: counter,
            state: { value: 0 },
            target: dashboardCounterValue(counter),
        }));
        const percentageStates = [...page.querySelectorAll('[data-dashboard-percentage]')].map((percentage) => ({
            element: percentage,
            state: { value: 0 },
            target: dashboardPercentageValue(percentage),
        }));
        const segmentStates = [...page.querySelectorAll('[data-dashboard-chart-segment]')].map((segment) => ({
            element: segment,
            length: Number.parseFloat(segment.dataset.segmentLength ?? '0'),
            state: { progress: 0 },
        }));

        page._dashboardCounterStates = counterStates;
        page._dashboardPercentageStates = percentageStates;
        page._dashboardSegmentStates = segmentStates;

        gsap.set(header, { opacity: 0, y: 20 });
        gsap.set(stats, { opacity: 0, y: 26, transition: 'none' });
        gsap.set(distribution, { opacity: 0, y: 16 });
        gsap.set(recent, { opacity: 0, x: 12 });

        counterStates.forEach(({ element }) => {
            element.textContent = '0';
        });
        percentageStates.forEach(({ element }) => {
            element.textContent = '0.0%';
        });

        segmentStates.forEach(({ element }) => {
            element.style.strokeDasharray = '0 100';
        });

        const timeline = gsap.timeline({
            paused: true,
            defaults: { ease: 'power3.out' },
            onComplete: () => {
                gsap.set(targets, { clearProps: 'opacity,transform,visibility' });
                gsap.set(stats, { clearProps: 'transition' });
                restoreDashboardCounterValues(page);
                restoreDashboardPercentageValues(page);
                restoreDashboardChartSegments(page);
                page._dashboardCounterStates = null;
                page._dashboardPercentageStates = null;
                page._dashboardSegmentStates = null;
                page._dashboardAnimationTimeline = null;
            },
        });

        page._dashboardAnimationTimeline = timeline;
        timeline.to(header, {
            duration: 0.65,
            opacity: 1,
            y: 0,
        }, 0.08).to(stats, {
            duration: 0.8,
            opacity: 1,
            y: 0,
            stagger: 0.08,
        }, 0.18).to(distribution, {
            duration: 0.85,
            opacity: 1,
            y: 0,
            ease: 'power2.out',
        }, 0.28).to(recent, {
            duration: 0.8,
            opacity: 1,
            x: 0,
        }, 0.35);

        counterStates.forEach(({ element, state, target }) => {
            const start = element.closest('[data-dashboard-stat]') ? 0.18 : dashboardDistributionStart(element);

            timeline.to(state, {
                duration: 1.35,
                value: target,
                ease: 'power2.out',
                snap: { value: 1 },
                onUpdate: () => {
                    element.textContent = String(Math.round(state.value));
                },
            }, start);
        });

        percentageStates.forEach(({ element, state, target }) => {
            timeline.to(state, {
                duration: 1.35,
                value: target,
                ease: 'power2.out',
                onUpdate: () => {
                    element.textContent = `${state.value.toFixed(1)}%`;
                },
            }, dashboardDistributionStart(element));
        });

        segmentStates.forEach(({ element, length, state }, index) => {
            timeline.to(state, {
                duration: 0.68,
                progress: 1,
                ease: 'power2.out',
                onUpdate: () => {
                    const visibleLength = length * state.progress;

                    element.style.strokeDasharray = `${visibleLength} ${Math.max(100 - visibleLength, 0)}`;
                },
            }, 0.2 + (index * 0.07));
        });

        document.documentElement.classList.remove('dashboard-animation-pending');
        timeline.play(0);
    } catch {
        restoreDashboardAnimationState(page);
    }
}

function restoreDashboardAfterHistoryNavigation(event) {
    if (!event.persisted) {
        return;
    }

    const page = document.querySelector('[data-dashboard-page]');

    if (page) {
        restoreDashboardAnimationState(page);
    }

    const root = document.documentElement;
    const sidebar = document.querySelector('[data-dashboard-sidebar]');

    root._shellAnimationTimeline?.kill();
    root._shellAnimationTimeline = null;
    root.classList.remove('shell-animation-pending');

    if (sidebar) {
        gsap.killTweensOf(sidebar);
        gsap.set(sidebar, { clearProps: 'opacity,transform,visibility' });
    }

    const header = document.querySelector('[data-mobile-header]');
    const headerTargets = [header, header?.querySelector('[data-mobile-header-brand]'), header?.querySelector('[data-mobile-header-menu]')].filter(Boolean);

    gsap.killTweensOf(headerTargets);
    gsap.set(headerTargets, { clearProps: 'opacity,transform,visibility' });

    const calibrationsPage = document.querySelector('[data-calibrations-page]');

    if (calibrationsPage) {
        restoreCalibrationAnimationState(calibrationsPage);
        calibrationsPage.dataset.animationsInitialized = 'false';
    }
}

function cleanupDashboardBeforeNavigation() {
    const page = document.querySelector('[data-dashboard-page]');

    if (page) {
        restoreDashboardAnimationState(page);
    }

    const root = document.documentElement;
    const sidebar = document.querySelector('[data-dashboard-sidebar]');

    root._shellAnimationTimeline?.kill();
    root._shellAnimationTimeline = null;
    root.classList.remove('shell-animation-pending');

    if (sidebar) {
        gsap.killTweensOf(sidebar);
        gsap.set(sidebar, { clearProps: 'opacity,transform,visibility' });
    }

    const header = document.querySelector('[data-mobile-header]');
    const headerTargets = [header, header?.querySelector('[data-mobile-header-brand]'), header?.querySelector('[data-mobile-header-menu]')].filter(Boolean);

    gsap.killTweensOf(headerTargets);
    gsap.set(headerTargets, { clearProps: 'opacity,transform,visibility' });

    const calibrationsPage = document.querySelector('[data-calibrations-page]');

    if (calibrationsPage) {
        restoreCalibrationAnimationState(calibrationsPage);
    }
}

window.instrumentsDeskNotifications = {
    success: (message) => notify('success', message),
    error: (message) => notify('error', message),
    warning: (message) => notify('warning', message),
    info: (message) => notify('info', message),
    confirmation: confirmCancellation,
    completionConfirmation: confirmCompletion,
    testTablePublishConfirmation: confirmTestTablePublish,
    testTableForkConfirmation: confirmTestTableFork,
    testTableArchiveConfirmation: confirmTestTableArchive,
    testTableRestoreConfirmation: confirmTestTableRestore,
    testTableDeleteConfirmation: openTestTableDeleteModal,
    testRowRemovalConfirmation: confirmTestRowRemoval,
    testColumnRemovalConfirmation: confirmTestColumnRemoval,
    activityRemovalConfirmation: confirmActivityRemoval,
    standardRemovalConfirmation: confirmStandardRemoval,
    standardAssignmentRemovalConfirmation: confirmStandardAssignmentRemoval,
    standardCreationConfirmation: confirmNewStandardCreation,
    standardDiscardConfirmation: confirmStandardDiscard,
    standardAssignmentConfirmation: confirmStandardAssignment,
    standardMasterDiscardConfirmation: confirmStandardMasterDiscard,
    activityMasterDiscardConfirmation: confirmActivityMasterDiscard,
    identityDiscardConfirmation: confirmIdentityDiscard,
    templateRecoveryConfirmation: confirmTemplateRecovery,
    testResultClearConfirmation: confirmTestResultClear,
};

document.addEventListener('livewire:init', registerLivewireNotifications);
document.addEventListener('DOMContentLoaded', registerMobileNavigation);
document.addEventListener('DOMContentLoaded', registerCertificateRefreshConfirmation);
document.addEventListener('DOMContentLoaded', registerUserStatusConfirmation);
document.addEventListener('DOMContentLoaded', initializeLoginAnimations);
document.addEventListener('DOMContentLoaded', initializeShellAnimations);
document.addEventListener('DOMContentLoaded', synchronizeTopLevelPageScrollPosition);
document.addEventListener('DOMContentLoaded', initializeDashboardAnimations);
document.addEventListener('DOMContentLoaded', initializeInstrumentsAnimations);
document.addEventListener('DOMContentLoaded', initializeCalibrationsAnimations);
document.addEventListener('livewire:navigated', registerMobileNavigation);
document.addEventListener('livewire:navigated', registerCertificateRefreshConfirmation);
document.addEventListener('livewire:navigated', registerUserStatusConfirmation);
document.addEventListener('livewire:navigated', initializeLoginAnimations);
document.addEventListener('livewire:navigated', initializeShellAnimations);
document.addEventListener('livewire:navigated', synchronizeTopLevelPageScrollPosition);
document.addEventListener('livewire:navigated', initializeDashboardAnimations);
document.addEventListener('livewire:navigated', initializeInstrumentsAnimations);
document.addEventListener('livewire:navigated', initializeCalibrationsAnimations);
document.addEventListener('livewire:navigating', cleanupDashboardBeforeNavigation);
document.addEventListener('DOMContentLoaded', registerExternalPdfUpload);
document.addEventListener('livewire:navigated', registerExternalPdfUpload);
window.addEventListener('pageshow', restoreDashboardAfterHistoryNavigation);
window.addEventListener('pageshow', synchronizeTopLevelPageScrollPosition);

if (window.Livewire) {
    registerLivewireNotifications();
}
