<?php

namespace App\Enums;

enum ResultStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
