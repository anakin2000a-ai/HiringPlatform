<?php

namespace App\Enums;

enum QuestionnaireStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
