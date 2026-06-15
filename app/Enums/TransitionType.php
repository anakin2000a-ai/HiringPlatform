<?php

namespace App\Enums;

enum TransitionType: string
{
    case Manual    = 'manual';
    case Automatic = 'automatic';
}
