<?php

namespace App\Enums;

enum Confirmed: string
{
    case Unused = 'unused';
    case Yes = 'yes';
    case No = 'no';
    case Maybe = 'maybe';
}
