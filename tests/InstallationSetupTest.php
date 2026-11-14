<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class InstallationSetupTest extends TestCase {
 public function testSetup(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' setup 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testSecurity(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' security 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testClone(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' clone 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testScope(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' scope 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testService(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' service 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testBusy(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' busy 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testConnection(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' connection 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testConnectionEvent(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' connection_event 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testCancelPending(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' cancel_pending 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
 public function testServiceClone(): void {
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/installation-setup-harness.php').' service_clone 2>&1',$output,$code);
  self::assertSame(0,$code,implode("\n",$output));
  self::assertTrue(json_decode(implode("\n",$output),true)['ok']);
 }
}
