<?php

namespace ESolution\Inventory\Enums;

enum ValuationMethod: string
{
    case FIFO = 'fifo';
    case FEFO = 'fefo';
    case WEIGHTED_AVERAGE = 'weighted_average';
    case MOVING_AVERAGE = 'moving_average';
}
