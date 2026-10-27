<?php

namespace App\Authorization;

/**
 * Named application capabilities enforced by Gates.
 *
 * session.cancel stays denied as a global Gate capability. Cancel authorization
 * is owner-aware inside CancelCalibrationSession / AuthorizeCalibrationSessionCancel
 * and must never grant Engineers an unconditional cancel ability via this Gate.
 */
enum ApplicationCapability: string
{
    case MasterdataManage = 'masterdata.manage';
    case UsersManage = 'users.manage';
    case SessionStart = 'session.start';
    case SessionRecord = 'session.record';
    case SessionComplete = 'session.complete';
    case SessionCancel = 'session.cancel';
    case DocumentsUpload = 'documents.upload';
    case DocumentsDownload = 'documents.download';
    case HistoryView = 'history.view';
    case CertificatesView = 'certificates.view';
}
