<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfigurationCopyLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'source_store_id' => $this->source_store_id,
            'target_store_id' => $this->target_store_id,
            'copied_by'       => $this->copied_by,
            'copied_items'    => $this->copied_items,
            'metadata'        => $this->metadata,
            'created_at'      => $this->created_at,
        ];
    }
}
