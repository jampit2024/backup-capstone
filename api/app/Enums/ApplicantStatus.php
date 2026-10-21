<?php

namespace App\Enums;

enum ApplicantStatus: string
{
    case Registered = 'registered';
    case Confirmed = 'confirmed';
    case Examined = 'examined';
}
