<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
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

if (! class_exists('ActionScheduler_Store', false)) {
    /**
     * Minimal Action Scheduler status shim for unit tests.
     */
    final class ActionScheduler_Store
    {
        public const STATUS_PENDING = 'pending';

        public const STATUS_RUNNING = 'in-progress';
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

if (! class_exists('WC_Shipping_Method', false)) {
    /**
     * Minimal WooCommerce shipping method shim for unit tests.
     */
    class WC_Shipping_Method
    {
        public string $id = '';

        public int $instance_id = 0;

        public string $method_title = '';

        public string $method_description = '';

        /** @var list<string> */
        public array $supports = [];

        public string $enabled = 'yes';

        public string $title = '';

        /** @var list<array<string, mixed>> */
        public array $rates = [];

        public function add_rate(array $args): void
        {
            $this->rates[] = $args;
        }
    }
}

if (! class_exists('WC_Order_Item_Shipping', false)) {
    /**
     * Minimal WooCommerce shipping item shim for unit tests.
     */
    final class WC_Order_Item_Shipping
    {
        /** @var array<string, mixed> */
        public array $meta = [];

        public function add_meta_data(string $key, mixed $value, bool $unique = false): void
        {
            unset($unique);
            $this->meta[$key] = $value;
        }
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
            $cb = $GLOBALS['octavawms_test_wc_order_save_callback'] ?? null;
            if (is_callable($cb)) {
                $cb($this);
            }

            return true;
        }
    }
}

if (! function_exists('WC')) {
    function WC()
    {
        if (! isset($GLOBALS['octavawms_test_wc'])) {
            $GLOBALS['octavawms_test_wc'] = new class () {
                public object $session;

                public function __construct()
                {
                    $this->session = new class () {
                        /** @var array<string, mixed> */
                        private array $data = [];

                        public function get(string $key, mixed $default = null): mixed
                        {
                            return $this->data[$key] ?? $default;
                        }

                        public function set(string $key, mixed $value): void
                        {
                            $this->data[$key] = $value;
                        }
                    };
                }
            };
        }

        return $GLOBALS['octavawms_test_wc'];
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

if (! function_exists('octavawms_test_action_scheduler_matches')) {
    /**
     * @param array<string, mixed> $action
     * @param array<int, mixed>|null $args
     */
    function octavawms_test_action_scheduler_matches(array $action, string $hook, ?array $args, string $group, mixed $status = null): bool
    {
        if (($action['hook'] ?? null) !== $hook || ($action['group'] ?? '') !== $group) {
            return false;
        }
        if ($args !== null && ($action['args'] ?? null) !== $args) {
            return false;
        }
        if ($status === null || $status === '') {
            return true;
        }

        $statuses = is_array($status) ? $status : [$status];

        return in_array($action['status'] ?? ActionScheduler_Store::STATUS_PENDING, $statuses, true);
    }
}

if (! function_exists('as_get_scheduled_actions')) {
    /**
     * Test shim for Action Scheduler action queries.
     *
     * @param array<string, mixed> $args
     */
    function as_get_scheduled_actions($args = [], $return_format = OBJECT)
    {
        $cb = $GLOBALS['octavawms_test_as_get_scheduled_actions_callback'] ?? null;
        if (is_callable($cb)) {
            return $cb($args, $return_format);
        }

        $ids = [];
        foreach (($GLOBALS['octavawms_test_async_actions'] ?? []) as $idx => $action) {
            if (! is_array($action)) {
                continue;
            }
            if (! octavawms_test_action_scheduler_matches(
                $action,
                (string) ($args['hook'] ?? ''),
                isset($args['args']) && is_array($args['args']) ? $args['args'] : null,
                (string) ($args['group'] ?? ''),
                $args['status'] ?? null
            )) {
                continue;
            }
            $ids[] = (int) ($action['id'] ?? ($idx + 1));
            if (isset($args['per_page']) && count($ids) >= (int) $args['per_page']) {
                break;
            }
        }

        return ($return_format === 'ids' || $return_format === 'int') ? $ids : [];
    }
}

if (! function_exists('as_has_scheduled_action')) {
    /**
     * Test shim for Action Scheduler pending/running lookup.
     *
     * @param array<int, mixed>|null $args
     */
    function as_has_scheduled_action($hook, $args = null, $group = '')
    {
        $cb = $GLOBALS['octavawms_test_as_has_scheduled_action_callback'] ?? null;
        if (is_callable($cb)) {
            return $cb($hook, $args, $group);
        }

        foreach (($GLOBALS['octavawms_test_async_actions'] ?? []) as $action) {
            if (is_array($action) && octavawms_test_action_scheduler_matches(
                $action,
                (string) $hook,
                is_array($args) ? $args : null,
                (string) $group,
                [ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING]
            )) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('as_unschedule_all_actions')) {
    /**
     * Test shim for cancelling all pending matching Action Scheduler actions.
     *
     * @param array<int, mixed> $args
     */
    function as_unschedule_all_actions($hook, $args = [], $group = '')
    {
        $cb = $GLOBALS['octavawms_test_as_unschedule_all_actions_callback'] ?? null;
        if (is_callable($cb)) {
            return $cb($hook, $args, $group);
        }

        $GLOBALS['octavawms_test_unscheduled_actions'][] = [
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];

        $remaining = [];
        foreach (($GLOBALS['octavawms_test_async_actions'] ?? []) as $action) {
            if (is_array($action) && octavawms_test_action_scheduler_matches(
                $action,
                (string) $hook,
                is_array($args) ? $args : null,
                (string) $group,
                ActionScheduler_Store::STATUS_PENDING
            )) {
                continue;
            }
            $remaining[] = $action;
        }
        $GLOBALS['octavawms_test_async_actions'] = $remaining;
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
    function as_enqueue_async_action($hook, $args = [], $group = '', $unique = false, $priority = 10)
    {
        $cb = $GLOBALS['octavawms_test_as_enqueue_async_action_callback'] ?? null;
        if (is_callable($cb)) {
            return $cb($hook, $args, $group, $unique, $priority);
        }

        if ($unique && as_has_scheduled_action($hook, $args, $group)) {
            return 0;
        }

        $id = count($GLOBALS['octavawms_test_async_actions'] ?? []) + 1;
        $GLOBALS['octavawms_test_async_actions'][] = [
            'id' => $id,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'unique' => (bool) $unique,
            'priority' => (int) $priority,
        ];

        return $id;
    }
}
