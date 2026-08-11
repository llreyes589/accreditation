<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PlacesController extends Controller
{
    private const BASE = 'https://nominatim.openstreetmap.org';

    public function search(Request $r): JsonResponse
    {
        $q = $r->query('q');
        if (!is_string($q) || trim($q) === '') {
            return response()->json(['message' => 'Query parameter q is required'], 422);
        }

        $res = Http::withHeaders(['User-Agent' => 'psp-accred/1.0 (institution geo lookup)'])
            ->timeout(10)
            ->get(self::BASE . '/search', [
                'q' => $q,
                'format' => 'json',
                'limit' => 6,
                'addressdetails' => 1,
            ]);

        if (!$res->successful()) {
            return response()->json(['message' => 'Places service unavailable'], 502);
        }

        $items = array_map(fn ($x) => [
            'label' => $x['display_name'] ?? '',
            'lat' => isset($x['lat']) ? (float) $x['lat'] : null,
            'lon' => isset($x['lon']) ? (float) $x['lon'] : null,
            'raw' => $x,
        ], $res->json() ?? []);

        return response()->json($items);
    }
}
