<?php

namespace App\Support;

class ExamTestMode
{
    public static function enabled(): bool
    {
        $environment = strtolower((string) config('app.env', 'production'));

        return $environment !== 'production'
            && ($environment === 'local' || filter_var(config('app.exam_test_mode'), FILTER_VALIDATE_BOOLEAN));
    }
}