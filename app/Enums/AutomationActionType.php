<?php

namespace App\Enums;

enum AutomationActionType: string
{
    case MoveToStage       = 'move_to_stage';
    case RejectApplication = 'reject_application';
    case MarkHired         = 'mark_hired';
    case CreateActivity    = 'create_activity';
    case PublishEvent      = 'publish_event';
    case SetScore          = 'set_score';
    case IncrementScore    = 'increment_score';
}
