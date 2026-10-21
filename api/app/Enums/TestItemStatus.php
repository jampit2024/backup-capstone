<?php

namespace App\Enums;

enum TestItemStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
