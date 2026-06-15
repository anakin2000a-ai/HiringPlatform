<?php

namespace App\Enums;

enum UserRole: string
{
    case FranchiseAdmin = 'franchise_admin';
    case StoreManager   = 'store_manager';
    case Recruiter      = 'recruiter';
    case Viewer         = 'viewer';
}
