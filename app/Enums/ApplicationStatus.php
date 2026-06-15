<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Hired     = 'hired';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';
}
