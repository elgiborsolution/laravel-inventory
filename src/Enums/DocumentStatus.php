<?php

namespace ESolution\Inventory\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case WAITING_APPROVAL = 'waiting_approval';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REVERSED = 'reversed';
}
