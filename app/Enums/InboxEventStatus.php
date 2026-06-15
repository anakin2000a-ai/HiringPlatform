<?php

namespace App\Enums;

enum InboxEventStatus: string
{
    case Pending   = 'pending';
    case Processed = 'processed';
    case Parked    = 'parked';
    case Failed    = 'failed';
}
