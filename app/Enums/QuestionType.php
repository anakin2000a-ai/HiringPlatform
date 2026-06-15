<?php

namespace App\Enums;

enum QuestionType: string
{
    case Text        = 'text';
    case Number      = 'number';
    case Boolean     = 'boolean';
    case Date        = 'date';
    case Select      = 'select';
    case MultiSelect = 'multiselect';
}
