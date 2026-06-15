<?php

namespace App\Enums;

enum UserAccessScope: string
{
    case Franchise  = 'franchise';
    case MultiStore = 'multi_store';
    case Store      = 'store';
}
