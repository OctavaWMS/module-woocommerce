<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\IntegrationSourceImportAsyncSync;

final class IntegrationSourceImportAsyncSyncTest extends TestCase
{
    public function testMergeImportAsyncIntoSettingsCreatesGeneralAndSetsOrderFlag(): void
    {
        $out = IntegrationSourceImportAsyncSync::mergeImportAsyncIntoSettings(
            ['Products' => ['shop' => 1]],
            true
        );
        self::assertSame(1, $out['general'][IntegrationSourceImportAsyncSync::GENERAL_ASYNC_IMPORT_ORDER_ELEMENT]);
        self::assertSame(['shop' => 1], $out['Products']);
    }

    public function testMergeImportAsyncIntoSettingsDisables(): void
    {
        $out = IntegrationSourceImportAsyncSync::mergeImportAsyncIntoSettings(
            ['general' => ['url' => 'https://store.test']],
            false
        );
        self::assertSame(0, $out['general'][IntegrationSourceImportAsyncSync::GENERAL_ASYNC_IMPORT_ORDER_ELEMENT]);
        self::assertSame('https://store.test', $out['general']['url']);
    }

    public function testSyncImportAsyncSettingSkipsWhenSourceIdZero(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::never())->method('getIntegrationSource');
        $api->expects(self::never())->method('patchIntegrationSource');

        $r = IntegrationSourceImportAsyncSync::syncImportAsyncSetting($api, 0, true);
        self::assertTrue($r['ok']);
    }

    public function testSyncImportAsyncSettingPatchesMergedSettings(): void
    {
        $api = $this->createMock(BackendApiClient::class);
        $api->expects(self::once())
            ->method('getIntegrationSource')
            ->with(7)
            ->willReturn([
                'settings' => [
                    'general' => ['url' => 'https://pagashop.com'],
                    'Products' => ['shop' => 208713],
                ],
            ]);
        $api->expects(self::once())
            ->method('patchIntegrationSource')
            ->with(
                7,
                self::callback(static function (array $body): bool {
                    $g = $body['settings']['general'] ?? null;

                    return is_array($g)
                        && ($g['url'] ?? null) === 'https://pagashop.com'
                        && ($g[IntegrationSourceImportAsyncSync::GENERAL_ASYNC_IMPORT_ORDER_ELEMENT] ?? null) === 1
                        && ($body['settings']['Products']['shop'] ?? null) === 208713;
                })
            )
            ->willReturn([
                'ok' => true,
                'status' => 200,
                'data' => [],
                'raw' => '',
                'response_headers' => [],
            ]);

        $r = IntegrationSourceImportAsyncSync::syncImportAsyncSetting($api, 7, true);
        self::assertTrue($r['ok']);
        self::assertSame('', $r['message']);
    }

    public function testSyncImportAsyncSettingFailsWhenGetReturnsNull(): void
    {
        Functions\when('__')->alias(static fn (string $text, $domain = null): string => $text);

        $api = $this->createMock(BackendApiClient::class);
        $api->method('getIntegrationSource')->willReturn(null);
        $api->expects(self::never())->method('patchIntegrationSource');

        $r = IntegrationSourceImportAsyncSync::syncImportAsyncSetting($api, 3, true);
        self::assertFalse($r['ok']);
        self::assertNotSame('', $r['message']);
    }
}
