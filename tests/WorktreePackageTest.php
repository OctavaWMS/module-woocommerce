<?php
declare(strict_types=1);
namespace Tests\OctavaWMS\WooCommerce;
use PHPUnit\Framework\TestCase;
final class WorktreePackageTest extends TestCase
{
    public function testBothGitPointerAndGitDirectoryStayOutOfTheMerchantZip(): void
    {
        $root = sys_get_temp_dir() . '/izprati-package-' . bin2hex(random_bytes(8));
        mkdir($root . '/scripts', 0700, true);
        mkdir($root . '/src', 0700, true);
        mkdir($root . '/languages', 0700, true);
        copy(dirname(__DIR__) . '/scripts/build-plugin-zip.sh', $root . '/scripts/build-plugin-zip.sh');
        file_put_contents($root . '/octavawms-woocommerce.php', "<?php\n/**\n * Version: 0.0.1\n */\n");
        file_put_contents($root . '/readme.txt', "Stable tag: 0.0.1\n");
        file_put_contents($root . '/changelog.txt', "2026-11-13 - version 0.0.1\n");
        file_put_contents($root . '/LICENSE', 'Synthetic GPL fixture');
        file_put_contents($root . '/src/Distribution.php', "<?php\npublic const VERSION = '0.0.1';\n");
        try {
            foreach ([false, true] as $directory) {
                if ($directory) { unlink($root . '/.git'); mkdir($root . '/.git'); file_put_contents($root . '/.git/config', 'synthetic internal metadata'); }
                else { file_put_contents($root . '/.git', 'gitdir: /private/synthetic/internal'); }
                exec('bash ' . escapeshellarg($root . '/scripts/build-plugin-zip.sh') . ' 2>&1', $output, $code);
                self::assertSame(0, $code, implode("\n", $output));
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($root . '/dist/izprati-bulgaria-shipping-0.0.1.zip'));
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    self::assertDoesNotMatchRegularExpression('~(^|/)\\.git(/|$)~', $zip->getNameIndex($i));
                }
                self::assertNotFalse($zip->locateName('izprati-bulgaria-shipping/octavawms-woocommerce.php'));
                $zip->close();
                $output = [];
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($root);
        }
    }
}
