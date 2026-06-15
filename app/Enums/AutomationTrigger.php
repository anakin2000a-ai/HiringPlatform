<?php

namespace App\Enums;

enum AutomationTrigger: string
{
    case ApplicationCreated = 'application_created';
    case StageEntered       = 'stage_entered';
    case AnswerSubmitted    = 'answer_submitted';
    case DocumentSubmitted  = 'document_submitted';
    case DocumentSigned     = 'document_signed';
    case DocumentApproved   = 'document_approved';
    case DocumentRejected   = 'document_rejected';
}
