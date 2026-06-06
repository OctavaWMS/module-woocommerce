<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! class_exists('WP_Error', false)) {
    /**
     * Minimal WordPress transport error shim for unit tests.
     */
    final class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! class_exists('WC_Integration', false)) {
    /**
     * Minimal WooCommerce settings API shim for unit tests.
     */
    class WC_Integration
    {
        public string $id = '';

        public string $method_title = '';

        public string $method_description = '';

        /** @var array<string, array<string, mixed>> */
        public array $form_fields = [];

        /** @var array<string, mixed> */
        public array $settings = [];

        public function init_form_fields(): void {}

        public function init_settings(): void
        {
            $settings = get_option($this->get_option_key(), []);
            $this->settings = is_array($settings) ? $settings : [];
        }

        public function process_admin_options(): void
        {
            $settings = $this->settings;
            foreach ($this->form_fields as $key => $field) {
                if (($field['type'] ?? '') === 'title') {
                    continue;
                }

                $postKey = 'woocommerce_' . $this->id . '_' . $key;
                if (($field['type'] ?? '') === 'checkbox') {
                    $settings[$key] = isset($_POST[$postKey]) ? 'yes' : 'no';
                    continue;
                }

                if (isset($_POST[$postKey])) {
                    $settings[$key] = (string) $_POST[$postKey];
                }
            }

            $this->settings = $settings;
            update_option($this->get_option_key(), $settings);
        }

        public function get_option(string $key, mixed $emptyValue = null): mixed
        {
            return $this->settings[$key] ?? $emptyValue;
        }

        public function get_option_key(): string
        {
            return 'woocommerce_' . $this->id . '_settings';
        }

        public function admin_options(): void {}
    }
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
            private string $octavaMetaValue = '',
            private string $orderNumber = ''
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

            return $this->updatedMeta[$key] ?? '';
        }

        public function get_order_key(): string
        {
            return $this->orderKey;
        }

        public function get_order_number(): string
        {
            if ($this->orderNumber !== '') {
                return $this->orderNumber;
            }

            return $this->id > 0 ? (string) $this->id : '';
        }

        /** @var array<string, mixed> */
        public array $updatedMeta = [];

        /** @var list<string> */
        public array $orderNotes = [];

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->updatedMeta[$key] = $value;
        }

        public function delete_meta_data(string $key): void
        {
            unset($this->updatedMeta[$key]);
        }

        public function get_payment_method(): string
        {
            return '';
        }

        public function get_payment_method_title(): string
        {
            return '';
        }

        public function get_total(): string
        {
            return '0';
        }

        public function get_currency(): string
        {
            return 'EUR';
        }

        public function get_items(): array
        {
            return [];
        }

        public function add_order_note(string $note): void
        {
            $this->orderNotes[] = $note;
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

if (! function_exists('as_enqueue_async_action')) {
    /**
     * Test shim for WooCommerce Action Scheduler.
     *
     * @param string $hook
     * @param array<int, mixed> $args
     * @param string $group
     */
    function as_enqueue_async_action($hook, $args = [], $group = '')
    {
        $cb = $GLOBALS['octavawms_test_as_enqueue_async_action_callback'] ?? null;
        if (is_callable($cb)) {
            return $cb($hook, $args, $group);
        }

        $GLOBALS['octavawms_test_async_actions'][] = [
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];

        return count($GLOBALS['octavawms_test_async_actions']);
    }
}
