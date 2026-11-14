<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/** An organizer sees only the invoices and credit notes of her own purchases. */
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->user_id === $user->id;
    }

    public function viewCreditNote(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }
}
