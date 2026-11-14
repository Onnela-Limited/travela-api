<?php
namespace App\Support;

use App\Models\PartnerApplication;
use Illuminate\Support\Facades\Storage;

class PartnerApplicationDocuments {
    public const KINDS = ['id_front','id_back','selfie','registration','director_id'];
    public static function required(?string $type): array {
        return ['id_front','id_back', ...(in_array($type, ['hotel','tour_operator','event_organiser','airline'], true) ? ['registration','director_id'] : ['selfie'])];
    }
    public static function retained(PartnerApplication $app, array $requested): array {
        $valid = [];
        foreach (self::KINDS as $kind) {
            $path = $app->documents[$kind] ?? null;
            if (in_array($kind, $requested, true) && is_string($path) && str_starts_with($path, 'partner-applications/'.$app->reference.'/') && !str_contains($path, '..') && Storage::disk('local')->exists($path)) $valid[$kind] = $path;
        }
        return $valid;
    }
}
