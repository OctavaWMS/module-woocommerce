<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Api;

use OctavaWMS\WooCommerce\Api\BackendApiClient;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class BackendApiClientPlacesDedupeTest extends TestCase
{
    public function testDedupePlacesByIdKeepsFirstOccurrence(): void
    {
        $client = new BackendApiClient();
        $m = new \ReflectionMethod(BackendApiClient::class, 'dedupePlacesByIdPreserveOrder');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($client, [
            ['id' => 10, 'weight' => 1],
            ['id' => 10, 'weight' => 99],
            ['id' => 20, 'weight' => 2],
        ]);
        self::assertCount(2, $out);
        self::assertSame(10, $out[0]['id']);
        self::assertSame(1, $out[0]['weight']);
        self::assertSame(20, $out[1]['id']);
    }

    public function testDedupePlacesByIdAppendsRowsWithoutNumericId(): void
    {
        $client = new BackendApiClient();
        $m = new \ReflectionMethod(BackendApiClient::class, 'dedupePlacesByIdPreserveOrder');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($client, [
            ['id' => 1],
            ['weight' => 1],
        ]);
        self::assertCount(2, $out);
    }

    public function testDedupePlacesByIdUnifiesStringAndIntId(): void
    {
        $client = new BackendApiClient();
        $m = new \ReflectionMethod(BackendApiClient::class, 'dedupePlacesByIdPreserveOrder');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($client, [
            ['id' => '10', 'weight' => 1],
            ['id' => 10, 'weight' => 99],
        ]);
        self::assertCount(1, $out);
        self::assertSame(1, $out[0]['weight']);
    }
}
