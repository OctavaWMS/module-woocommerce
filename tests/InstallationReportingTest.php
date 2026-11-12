<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class InstallationReportingTest extends TestCase {
 public function testConsentOutageRetriesWithdrawalAndClones(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__ . '/installation-reporting-harness.php').' 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
}
