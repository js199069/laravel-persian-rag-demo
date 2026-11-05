<?php

namespace Js199069\PersianRag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagDocument extends Model
{
    protected $guarded = [];

    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class, 'rag_document_id');
    }
}
