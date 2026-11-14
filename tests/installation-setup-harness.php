<?php
// Isolated WordPress double: no source is loaded into an existing WordPress process.
declare(strict_types=1);
const DAY_IN_SECONDS = 86400;
$options = []; $calls = []; $scheduled = []; $site = getenv('WP_SETUP_SITE') ?: 'https://fixture.example.com/shop'; $wp_version = '6.8'; $mode = 'ok'; $allowed=true; $validNonce=true; $screen=(object)['base'=>'plugins']; $hooks=[];
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = false) { global $options; if (isset($options[$key])) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }
function home_url($path = '') { global $site; return $site . $path; }
function wp_generate_uuid4() { $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function get_bloginfo($key) { return getenv('WP_SETUP_EXPORT') ? (getenv('WP_SETUP_LABEL') ?: 'SYNTHETIC FIRST SETUP VERIFICATION') : 'Synthetic reporting fixture'; }
function get_file_data($file, $headers) {preg_match('/Version:\s*([^\s]+)/',file_get_contents($file),$m);return ['version'=>$m[1]??'unknown'];}
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
 if (str_ends_with($url, '/connect')) return ['code'=>$mode==='connect_fail'?403:200,'body'=>json_encode($mode==='connect_fail'?['status'=>'error','message'=>'Rejected']:['status'=>'ok','apiKey'=>'synthetic-service-key','sourceId'=>42])];
 $body=json_decode($request['body'], true); $calls[]=['url'=>$url,'request'=>$request,'body'=>$body];
 if ($mode === 'fail') return ['code'=>503,'body'=>'{}'];
 if ($mode === 'badack') return ['code'=>200,'body'=>'{"ok":true,"eventId":"wrong"}'];
 return ['code'=>200,'body'=>json_encode(['ok'=>true,'eventId'=>$body['eventId']])];
}
function verify($value,$label) {if (!$value) throw new RuntimeException($label);}

function current_user_can($cap) {global $allowed;return $allowed;}
function is_network_admin() {return false;}
function get_current_screen() {global $screen;return $screen;}
function esc_html__($s,$d) {return htmlspecialchars($s,ENT_QUOTES);}
function esc_attr($s) {return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_url($s) {return htmlspecialchars($s,ENT_QUOTES);}
function admin_url($s) {return 'https://fixture.example.com/wp-admin/'.$s;}
function wp_nonce_field($action) {echo '<input name="_wpnonce" value="synthetic">';}
function check_admin_referer($action) {global $validNonce;if(!$validNonce)throw new RuntimeException('nonce');}
function wp_unslash($s) {return $s;}
function sanitize_email($s) {return filter_var($s,FILTER_VALIDATE_EMAIL) ? $s : '';}
function wp_die($s,$title='', $args=[]) {throw new RuntimeException('denied:'.($args['response']??400));}
function wp_safe_redirect($s) {throw new RuntimeException('redirect');}
function add_action($name,$callback) {global $hooks;$hooks[$name][]=$callback;}
function do_action($name) {global $hooks;foreach($hooks[$name]??[] as $callback)$callback();}
function apply_filters($name,$value,...$args) {return $value;}
function is_ssl() {return true;}
function wp_parse_url($url,$component=-1) {return parse_url($url,$component);}
function check_ajax_referer(...$args) {global $validNonce;if(!$validNonce)throw new RuntimeException('nonce');}
function wp_send_json_success($data) {throw new RuntimeException('ajax:success');}
function wp_send_json_error($data,$status=400) {throw new RuntimeException('ajax:error');}
function attempt($run,$expected) {try{$run();throw new RuntimeException('missing redirect or denial');}catch(RuntimeException $e){verify($e->getMessage()===$expected,$e->getMessage().' expected '.$expected);}}
require dirname(__DIR__) . '/src/InstallationReporting.php';
require dirname(__DIR__) . '/src/Options.php';
$class='OctavaWMS\\WooCommerce\\InstallationReporting';$reporter=new $class();$key=$class::OPTION;
$reporter->register();$_SERVER['REQUEST_METHOD']='POST';$case=$argv[1]??'setup';
if($case==='setup') {
 verify($reporter->needsSetup(),'first visit needs choice');ob_start();$reporter->setupNotice();$html=ob_get_clean();
 verify(str_contains($html,'Register and share store details')&&str_contains($html,'Continue without reporting'),'clear consent/skip choices');
 verify(!str_contains($html,'checked'),'no preselected consent');verify(count($calls)===0,'view sends no request');
 $_POST=['setup_choice'=>'skip','setup_email'=>'private@example.com'];attempt(fn()=>$reporter->saveSetup(),'redirect');
 verify(!$reporter->needsSetup()&&count($calls)===0,'skip persists without network');
 verify(empty($options[$key]['email']),'skip does not retain email');
 $options=[];$_POST=['setup_choice'=>'register','setup_email'=>'merchant@example.com'];$mode='fail';attempt(fn()=>$reporter->saveSetup(),'redirect');
 verify(!$reporter->needsSetup()&&count($calls)===1,'consent attempts registration automatically');
 $first=$calls[0]['body'];verify($first['eventName']==='registered'&&$first['contactEmail']==='merchant@example.com','consented registration');
 $options[$key]['nextAttempt']=0;$mode='ok';$reporter->tick();verify($calls[1]['body']===$first,'outage retry uses same event');
 $reporter->tick();verify(count($calls)===2,'acknowledged registration not repeated');
 $_POST=['setup_choice'=>'skip'];attempt(fn()=>$reporter->saveSetup(),'redirect');verify($options[$key]['enabled'],'stale setup form cannot revoke chosen consent');
} elseif($case==='security') {
 $_POST=['setup_choice'=>'register'];$_SERVER['REQUEST_METHOD']='GET';attempt(fn()=>$reporter->saveSetup(),'denied:405');
 $_SERVER['REQUEST_METHOD']='POST';$allowed=false;attempt(fn()=>$reporter->saveSetup(),'denied:403');
 ob_start();$reporter->setupNotice();verify(ob_get_clean()==='','no setup for unauthorized users');
 $allowed=true;$validNonce=false;attempt(fn()=>$reporter->saveSetup(),'nonce');$validNonce=true;
 $_POST=['setup_choice'=>'unexpected'];attempt(fn()=>$reporter->saveSetup(),'denied:400');verify($options===[]&&$calls===[],'rejected requests have no side effects');
} elseif($case==='clone') {
 $_POST=['setup_choice'=>'register'];attempt(fn()=>$reporter->saveSetup(),'redirect');$first=$calls[0]['body'];
 $site='https://staging.example.com/shop';verify($reporter->needsSetup(),'clone gets fresh setup');$before=count($calls);$reporter->tick();verify(count($calls)===$before,'clone cannot send inherited reports');
 $_POST=['setup_choice'=>'register'];attempt(fn()=>$reporter->saveSetup(),'redirect');verify(end($calls)['body']['siteId']!==$first['siteId'],'fresh clone identity');
} elseif($case==='scope') {
 $screen=(object)['base'=>'dashboard'];$_GET=[];ob_start();$reporter->setupNotice();verify(ob_get_clean()==='','no global dashboard nag');
 $_GET=['page'=>'wc-settings','tab'=>'integration','section'=>'octavawms'];ob_start();$reporter->setupNotice();verify(str_contains(ob_get_clean(),'Register and share store details'),'prompt appears in plugin setup');
}
elseif($case==='service') {
 $options['octavawms_api_key']='synthetic-service-key';$reporter->observe();verify($calls===[],'copied/manual API key does not enroll');
 $_POST=['setup_choice'=>'skip'];attempt(fn()=>$reporter->saveSetup(),'redirect');
 do_action('octavawms_service_connected');verify(!empty($options[$key]['enabled'])&&$calls===[],'service success queues without delaying connection');
 $reporter->tick();verify(count($calls)===1&&$calls[0]['body']['connected']===true,'connected site registers');
 verify($calls[0]['body']['contactEmail']===null,'no implicit admin email sharing');
 do_action('octavawms_service_connected');$reporter->tick();verify(count($calls)===1,'reconnect does not duplicate registration');
} elseif($case==='busy') {
 $options['octavawms_api_key']='synthetic-service-key';$options[$key.'_lock']=time()+120;
 do_action('octavawms_service_connected');verify(!empty($options[$key.'_service_pending'])&&!empty($scheduled[$class::HOOK]),'busy registration intent is durable and scheduled');
 $reporter->tick();verify(!empty($options[$key.'_service_pending'])&&!empty($scheduled[$class::HOOK]),'busy retry remains scheduled');
 unset($options[$key.'_lock']);$reporter->tick();verify(count($calls)===1&&empty($options[$key.'_service_pending']),'cron completes deferred registration');
 $site='https://copied.example.com';$options[$key.'_service_pending']=['siteUrl'=>'https://fixture.example.com/shop','consentAt'=>gmdate('Y-m-d\TH:i:s\Z')];
 $reporter->tick();verify(count($calls)===1&&!$options[$key]['enabled'],'clone cannot consume pending connection consent');
} elseif($case==='service_clone') {
 $_POST=['setup_choice'=>'register','setup_email'=>'source@example.com'];attempt(fn()=>$reporter->saveSetup(),'redirect');$original=$calls[0]['body'];
 $site='https://cloned.example.com/shop';$options['octavawms_api_key']='synthetic-service-key';do_action('octavawms_service_connected');$reporter->tick();
 verify(count($calls)===2&&$calls[1]['body']['siteId']!==$original['siteId'],'fresh service connect creates clone identity');
 verify($calls[1]['body']['contactEmail']===null,'clone connection does not inherit shared contact');
} elseif($case==='connection_event') {
 $_POST=['setup_choice'=>'register'];attempt(fn()=>$reporter->saveSetup(),'redirect');verify(!$calls[0]['body']['connected'],'consented store initially unconnected');
 $options['octavawms_api_key']='synthetic-service-key';do_action('octavawms_service_connected');$reporter->tick();
 verify(count($calls)===2&&$calls[1]['body']['eventName']==='connected','later connection emits connected event');
 do_action('octavawms_service_connected');$reporter->tick();verify(count($calls)===2,'reconnect emits no duplicate');
} elseif($case==='cancel_pending') {
 $options['octavawms_api_key']='synthetic-service-key';$options[$key.'_lock']=time()+120;do_action('octavawms_service_connected');
 unset($options[$key.'_lock']);verify($reporter->configure(false),'withdraw choice accepted');$reporter->tick();
 verify(empty($options[$key.'_service_pending'])&&empty($options[$key]['enabled'])&&$calls===[],'withdrawal cancels pending registration');
} elseif($case==='connection') {
 eval('namespace OctavaWMS\WooCommerce; class WooRestCredentials {public static function findOctavawmsKey(){return null;}} class PluginLog {public static function log(...$args){} public static function httpExchange(...$args){return [];}}');
 require dirname(__DIR__).'/src/Api/BackendApiClient.php';require dirname(__DIR__).'/src/ConnectService.php';
 $options['admin_email']='synthetic@example.com';$connector=new \OctavaWMS\WooCommerce\ConnectService();
 $mode='connect_fail';attempt(fn()=>$connector->handleAjaxConnect(),'ajax:error');verify(empty($options[$key])&&$calls===[],'failed service connection never enrolls');
 $mode='ok';attempt(fn()=>$connector->handleAjaxConnect(),'ajax:success');verify(!empty($options[$key]['queue'])&&$calls===[],'actual success action queues registration');
 $reporter->tick();verify(count($calls)===1&&$calls[0]['body']['connected'],'automatic notification path after successful connect');
}
$result=["ok"=>true,"case"=>$case,"registration"=>$calls[0]["body"]??null];
if(getenv("WP_SETUP_EXPORT") && !empty($calls[0])){$result["credential"]=substr($calls[0]["request"]["headers"]["Authorization"],7);}
echo json_encode($result),"\n";
