<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class PsgcController extends Controller
{
    private const BASE = 'https://psgc.gitlab.io/api';

    private function collect(string $path): JsonResponse
    {
        $res = Http::timeout(10)->get(self::BASE . '/' . ltrim($path, '/'));
        if (!$res->successful()) {
            return response()->json(['message' => 'PSGC upstream unavailable'], 502);
        }
        $data = $res->json();
        if (!is_array($data)) {
            return response()->json(['message' => 'PSGC upstream returned invalid data'], 502);
        }
        // Normalize to {code,name} for <select> options regardless of upstream keys.
        $items = array_map(fn($r) => [
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
        ], $data);

        return response()->json($items);
    }

    public function regions(): JsonResponse
    {
        return $this->collect('regions.json');
    }

    public function provinces(string $regionCode): JsonResponse
    {
        return $this->collect("regions/{$regionCode}/provinces.json");
    }

    public function cities(string $provinceCode): JsonResponse
    {
        return $this->collect("provinces/{$provinceCode}/cities-municipalities.json");
    }
}
