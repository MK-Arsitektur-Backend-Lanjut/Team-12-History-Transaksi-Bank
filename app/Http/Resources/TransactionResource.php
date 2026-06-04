<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'transaction_date' => $this->transaction_date->toDateTimeString(),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balance_before' => $this->balance_before !== null ? (float) $this->balance_before : null,
            'balance_after' => $this->balance_after !== null ? (float) $this->balance_after : null,
            'description' => $this->description,
        ];
    }
}
