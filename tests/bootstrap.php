<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! class_exists('WC_Order', false)) {
    /**
     * Minimal stub for unit tests (WooCommerce is not loaded in PHPUnit).
     */
    final class WC_Order
    {
        public function __construct(
            private int $id = 42,
            private string $orderKey = 'wc_order_testkey99',
            private string $octavaMetaValue = ''
        ) {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_meta(string $key, bool $single = true): mixed
        {
            unset($single);
            if ($key === '_octavawms_external_order_id') {
                return $this->octavaMetaValue;
            }

            return '';
        }

        public function get_order_key(): string
        {
            return $this->orderKey;
        }

        /** @var array<string, mixed> */
        public array $updatedMeta = [];

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->updatedMeta[$key] = $value;
        }

        public int $saveCallCount = 0;

        public function save(): bool
        {
            $this->saveCallCount++;

            return true;
        }
    }
}

if (! function_exists('wc_get_order')) {
    /**
     * Test shim: tests set {@see $GLOBALS['octavawms_test_wc_get_order_callback']}.
     *
     * @param int|\WC_Order|false $order
     */
    function wc_get_order($order = false)
    {
        $cb = $GLOBALS['octavawms_test_wc_get_order_callback'] ?? null;

        return is_callable($cb) ? $cb($order) : false;
    }
}
