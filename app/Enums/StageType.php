<?php

namespace App\Enums;

enum StageType: string
{
    case Application = 'application';
    case Screening   = 'screening';
    case Interview   = 'interview';
    case Documents   = 'documents';
    case Approval    = 'approval';
    case Onboarding  = 'onboarding';
    case Hired       = 'hired';
    case Rejected    = 'rejected';
    case Custom      = 'custom';
}
