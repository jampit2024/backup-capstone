<?php

namespace App\Enums;

enum ExamSessionStatus: string
{
    case Open = 'open';
    case LobbyOpen = 'lobby_open';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Closed = 'closed';
}
