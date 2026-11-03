import Swal from 'sweetalert2';

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
        notify(type, message);
    });

    window.Livewire.on('instruments-desk-terminal-success', (payload) => {
        showTerminalSuccess(payload);
    });

    window.Livewire.on('instruments-desk-confirm-cancellation', (payload) => {
        confirmCancellation(payload);
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

if (window.Livewire) {
    registerLivewireNotifications();
}
