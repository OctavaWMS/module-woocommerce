<?php

declare(strict_types=1);

namespace Tests\OctavaWMS\WooCommerce\Admin;

use Brain\Monkey\Functions;
use OctavaWMS\WooCommerce\Admin\LabelAjax;
use OctavaWMS\WooCommerce\Admin\LabelMetaBox;
use OctavaWMS\WooCommerce\Api\BackendApiClient;
use OctavaWMS\WooCommerce\Api\LabelService;
use Tests\OctavaWMS\WooCommerce\TestCase;

final class LabelAjaxTest extends TestCase
{
    public function testHandleAjaxOrderStatusSendsErrorWhenOrderIdMissing(): void
    {
        $_POST = [];
        $_REQUEST = [];

        Functions\when('__')->returnArg(1);

        Functions\expect('wp_send_json_error')
            ->once()
            ->andReturnUsing(static function (): void {
                throw new \RuntimeException('wp_send_json_error');
            });

        $labelService = $this->getMockBuilder(LabelService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $api = new BackendApiClient();
        $ajax = new LabelAjax($api, $labelService, new LabelMetaBox());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error');

        $ajax->handleAjaxOrderStatus();
    }
}
