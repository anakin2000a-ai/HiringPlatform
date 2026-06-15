<?php

namespace App\Enums;

/**
 * Known event_type values written to workflow_activities.
 * The DB column stays string(100) for forward compatibility.
 * All application code must use these constants when writing known events.
 */
enum WorkflowEventType: string
{
    case StageMoved                    = 'stage_moved';
    case AutomationActivity            = 'automation_activity';
    case AutomationActionFailed        = 'automation_action_failed';
    case ScoreSet                      = 'score_set';
    case ScoreIncremented              = 'score_incremented';
    case ApplicantDocumentSubmitted    = 'applicant_document_submitted';
    case ApplicantDocumentSigned       = 'applicant_document_signed';
    case ApplicantDocumentApproved     = 'applicant_document_approved';
    case ApplicantDocumentRejected     = 'applicant_document_rejected';
}
