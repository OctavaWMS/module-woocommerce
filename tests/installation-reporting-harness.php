<?php
// Isolated WordPress double: no source is loaded into an existing WordPress process.
declare(strict_types=1);
const DAY_IN_SECONDS = 86400;
$options = []; $calls = []; $scheduled = []; $site = 'https://fixture.example.com/shop'; $wp_version = '6.8'; $mode = 'ok';
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = false) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
function home_url($path = '') { global $site; return $site . $path; }
function wp_generate_uuid4() { $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function get_bloginfo($key) { return 'Synthetic reporting fixture'; }
function get_file_data($file, $headers) {return ['version'=>'1.6.3'];}
function get_locale() { return 'bg_BG'; }
function wp_json_encode($body) { return json_encode($body); }
function __($s, $domain) { return $s; }
function wp_next_scheduled($key) {global $scheduled;return $scheduled[$key] ?? false;}
function wp_clear_scheduled_hook($key) {global $scheduled;unset($scheduled[$key]);}
function wp_schedule_single_event($at, $key) {global $scheduled;$scheduled[$key]=$at;return true;}
function is_wp_error($r) {return false;}
function wp_remote_retrieve_response_code($r) {return $r['code'];}
function wp_remote_retrieve_body($r) {return $r['body'];}
function wp_remote_post($url, $request) {
 global $calls,$mode;
 $body=json_decode($request['body'], true); $calls[]=['url'=>$url,'request'=>$request,'body'=>$body];
 if ($mode === 'fail') return ['code'=>503,'body'=>'{}'];
 if ($mode === 'badack') return ['code'=>200,'body'=>'{"ok":true,"eventId":"wrong"}'];
 return ['code'=>200,'body'=>json_encode(['ok'=>true,'eventId'=>$body['eventId']])];
}
function verify($value,$label) {if (!$value) throw new RuntimeException($label);}
require dirname(__DIR__) . '/src/InstallationReporting.php';
require dirname(__DIR__) . '/src/Options.php';
$class='OctavaWMS\\WooCommerce\\InstallationReporting';$reporter=new $class();$key=$class::OPTION;
$reporter->tick();verify(count($calls)===0,'disabled must never phone home');
$mode='fail';$reporter->configure(true,'merchant@example.com');$reporter->tick();
verify(count($calls)===1,'first attempt');$first=$calls[0]['body'];
verify($first['eventName']==='registered','register first');verify(count($options[$key]['queue'])===1,'persist during outage');
$credential=$calls[0]['request']['headers']['Authorization'];
verify(strlen(substr($credential,7))===43,'256-bit credential');
$options[$key]['nextAttempt']=0;$mode='badack';$reporter->tick();verify(count($options[$key]['queue'])===1,'bad acknowledgement retained');
$options[$key]['nextAttempt']=0;$mode='ok';$reporter->tick();
verify($calls[2]['body']===$first,'retry preserves exact event');verify($options[$key]['registered'],'registration acknowledged');
verify(count($options[$key]['queue'])===0,'ack removes event');
$reporter->configure(false);verify($options[$key]['email']==='','withdraw local contact');
verify(count($options[$key]['queue'])===1,'minimal withdrawal');verify($options[$key]['queue'][0]['contactEmail']===null,'no withdrawal contact');
$reporter->tick();$after=count($calls);$reporter->tick();verify(count($calls)===$after,'disabled stops observations');
$reporter->configure(true);$reporter->tick();verify(end($calls)['body']['eventName']==='registered','reconsent event');
$site='https://staging.example.com/shop';$options[$key]['nextAttempt']=0;$beforeClone=count($calls);$reporter->tick();
verify(count($calls)===$beforeClone,'clone requires fresh consent');verify(!$options[$key]['enabled'],'copied consent disabled');
$reporter->configure(true);$reporter->tick();$clone=end($calls);
verify($clone['body']['siteId']!==$first['siteId'],'clone identity changes');
verify($clone['request']['headers']['Authorization']!==$credential,'clone credentials change');
verify($clone['body']['sequence']===1,'clone starts sequence');
// Export only synthetic data for the cross-repository acceptance runner.
echo json_encode(['ok'=>true,'registration'=>$first,'credential'=>substr($credential,7)]),"\n";
