<?php

namespace App\Payments;

enum ProviderPaymentStatus
{
    case Open;
    case Paid;
    case Failed;
    case Expired;
    case Canceled;
}
