<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stores the engine's full event log for a played fixture, compressed. */
class MatchLog extends Model
{
    protected $primaryKey = 'fixture_id';

    public $incrementing = false;

    protected $fillable = ['fixture_id', 'log'];

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public static function encode(array $result): string
    {
        return base64_encode(gzencode(json_encode($result, JSON_UNESCAPED_UNICODE), 6));
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return json_decode(gzdecode(base64_decode($this->log)), true);
    }

    /** The raw JSON string, for embedding in a page without decoding twice. */
    public function json(): string
    {
        return gzdecode(base64_decode($this->log));
    }
}
