<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlacesSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_normalized_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'place_id' => 1,
                    'display_name' => 'Tacloban, Eastern Visayas, Philippines',
                    'lat' => '11.24',
                    'lon' => '125.01',
                    'class' => 'boundary',
                    'type' => 'administrative',
                    'address' => ['city' => 'Tacloban', 'state' => 'Eastern Visayas'],
                ],
            ], 200),
        ]);

        $r = $this->getJson('/api/places/search?q=Tacloban');
        $r->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'Tacloban, Eastern Visayas, Philippines')
            ->assertJsonPath('0.lat', 11.24)
            ->assertJsonPath('0.lon', 125.01);
    }

    public function test_search_requires_query(): void
    {
        $this->getJson('/api/places/search')->assertStatus(422);
    }
}
