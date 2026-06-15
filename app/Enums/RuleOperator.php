<?php

namespace App\Enums;

enum RuleOperator: string
{
    case Equals               = 'equals';
    case NotEquals            = 'not_equals';
    case GreaterThan          = 'greater_than';
    case GreaterThanOrEqual   = 'greater_than_or_equal';
    case LessThan             = 'less_than';
    case LessThanOrEqual      = 'less_than_or_equal';
    case Contains             = 'contains';
    case NotContains          = 'not_contains';
    case In                   = 'in';
    case NotIn                = 'not_in';
    case Exists               = 'exists';
    case NotExists            = 'not_exists';
    case IsEmpty              = 'is_empty';
    case IsNotEmpty           = 'is_not_empty';
}
