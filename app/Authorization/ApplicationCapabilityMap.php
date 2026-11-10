<?php

namespace App\Authorization;

/**
 * Static role → capability matrix for Instruments Desk.
 *
 * session.cancel remains denied for every role here. Owner-aware cancel rules
 * live in AuthorizeCalibrationSessionCancel (Administrator any / Engineer own).
 */
final class ApplicationCapabilityMap
{
    /**
     * @return list<ApplicationCapability>
     */
    public function capabilitiesFor(ApplicationRoleName $role): array
    {
        return match ($role) {
            ApplicationRoleName::Administrator => [
                ApplicationCapability::MasterdataView,
                ApplicationCapability::InstrumentsView,
                ApplicationCapability::MasterdataManage,
                ApplicationCapability::UsersManage,
                ApplicationCapability::SessionStart,
                ApplicationCapability::SessionRecord,
                ApplicationCapability::SessionComplete,
                ApplicationCapability::DocumentsUpload,
                ApplicationCapability::DocumentsDownload,
                ApplicationCapability::HistoryView,
                ApplicationCapability::SessionHistoryView,
                ApplicationCapability::CertificatesView,
            ],
            ApplicationRoleName::Engineer => [
                ApplicationCapability::MasterdataView,
                ApplicationCapability::InstrumentsView,
                ApplicationCapability::SessionStart,
                ApplicationCapability::SessionRecord,
                ApplicationCapability::SessionComplete,
                ApplicationCapability::DocumentsUpload,
                ApplicationCapability::DocumentsDownload,
                ApplicationCapability::HistoryView,
                ApplicationCapability::SessionHistoryView,
                ApplicationCapability::CertificatesView,
            ],
            ApplicationRoleName::Viewer => [
                ApplicationCapability::InstrumentsView,
                ApplicationCapability::HistoryView,
                ApplicationCapability::CertificatesView,
            ],
        };
    }

    public function allows(ApplicationRoleName $role, ApplicationCapability $capability): bool
    {
        if ($capability === ApplicationCapability::SessionCancel) {
            return false;
        }

        return in_array($capability, $this->capabilitiesFor($role), true);
    }
}
