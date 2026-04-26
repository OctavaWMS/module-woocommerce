<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

class Activation
{
    public const LABEL_SUBDIR = 'octavawms-labels';

    public static function run(): void
    {
        self::ensureLabelDirectory();
    }

    public static function ensureLabelDirectory(): void
    {
        $uploadDir = wp_upload_dir();

        if (! empty($uploadDir['error'])) {
            return;
        }

        $path = trailingslashit((string) $uploadDir['basedir']) . self::LABEL_SUBDIR . '/';
        if (! wp_mkdir_p($path)) {
            return;
        }

        $ht = $path . '.htaccess';
        if (file_exists($ht)) {
            return;
        }

        $content = <<<'HTA'
# OctavaWMS — block direct access to label files; downloads go through admin_post only.
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
HTA;

        file_put_contents($ht, $content);
    }
}
