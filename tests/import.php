<?php
// Mock-only source/API tests. No existing subscription is written or charged.
define('WP_INSTALLING',true);
require getenv('MI_WP_ROOT') ?: '/var/www/html/wp-load.php';
require dirname(__DIR__).'/mollie-incasso.php';
require __DIR__.'/FakeGateway.php';
require __DIR__.'/FixtureOptions.php';
use Lukraak\MollieIncasso\{Import,WooCommerceImport,ImportScreen,Plugin,Store,Failure};
use Lukraak\MollieIncasso\Tests\{FakeGateway,FixtureOptions};
FixtureOptions::start();
$checks=0;
function test(bool $condition,string $label): void {global $checks;if(!$condition)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function blocked(callable $call,string $reason):void {try{$call();}catch(Failure $e){test($e->reason===$reason,'blocked '.$reason);return;}throw new RuntimeException('Expected '.$reason);}
function wc_get_order($id){return $GLOBALS['mi_fixture_parent']??false;}
function wcs_get_subscriptions($args){$GLOBALS['mi_fixture_query']=$args;return $GLOBALS['mi_fixture_subscriptions']??[];}
final class MockWooImportOrder {
 public function __construct(public array $meta){}
 public function get_meta($key,$single=true){return $this->meta[$key]??'';}
 public function save(){throw new RuntimeException('Source write forbidden');}
}
final class MockWooImportSubscription {
 public array $meta=[];
 public string $period='month';public int $interval=1;public string $gateway='mollie_wc_gateway_ideal';public bool $manual=false;public array $dates=[];
 public function get_id(){return 101;}public function get_parent_id(){return 100;}
 public function get_meta($key,$single=true){return $this->meta[$key]??'';}
 public function get_billing_period(){return $this->period;}public function get_billing_interval(){return $this->interval;}
 public function get_payment_method(){return $this->gateway;}public function is_manual(){return $this->manual;}
 public function get_date($key,$timezone='gmt'){return $this->dates[$key]??'';}
 public function get_billing_first_name(){return 'Voorbeeld';}public function get_billing_last_name(){return 'Klant';}
 public function get_billing_email(){return 'example@example.invalid';}public function get_currency(){return 'EUR';}public function get_total(){return '20.00';}public function get_status(){return 'active';}
 public function save(){throw new RuntimeException('Source write forbidden');}public function update_status(){throw new RuntimeException('Source write forbidden');}
}
$originalPost=$_POST;$originalGet=$_GET;$originalServer=$_SERVER;$originalUser=get_current_user_id();
$db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$db->set_prefix('mi_import_test_'.bin2hex(random_bytes(3)).'_');
$store=new Store($db);$store->install();
try {
 $import=new Import();$adapter=new WooCommerceImport();
 $row=['source_site'=>'https://old.example.invalid/','subscription_id'=>'101','customer_name'=>'Voorbeeld Klant','customer_email'=>'example@example.invalid','mollie_customer_id'=>'cst_old','mollie_mandate_id'=>'mdt_old','mollie_payment_id'=>'tr_original','mode'=>'test','currency'=>'EUR','amount'=>'20.00','interval'=>'1 month','next_payment_date'=>gmdate('Y-m-d',time()+60*DAY_IN_SECONDS),'status'=>'active','parent_order_id'=>'100'];
 $csv=$import->serialize([$row]);
 test($import->csv($csv)===[ $row ],'canonical CSV round trip');
 test($import->analyze([$row],'test')[0]['candidate'],'valid basic fields produce a candidate only');
 test($import->csv("\xEF\xBB\xBF".str_replace(',',';',$csv))===[ $row ],'UTF-8 BOM and semicolon support');
 $quoted=$row;$quoted['customer_name']='Klant, "Voorbeeld"';test($import->csv($import->serialize([$quoted]))===[ $quoted ],'quoted commas and quotes retained');
 foreach ([['mode','live','environment_mismatch'],['currency','USD','import_currency'],['amount','0.00','invalid_amount'],['interval','1 week','import_interval'],['next_payment_date','2026-02-31','import_date'],['status','cancelled','import_source_inactive'],['mollie_mandate_id','','import_references_missing'],['customer_email','not-an-email','import_customer_missing'],['source_site','https://user:secret@example.invalid','import_source_missing']] as [$key,$value,$reason]) {
  $bad=$row;$bad[$key]=$value;test(in_array($reason,$import->analyze([$bad],'test')[0]['issues'],true),'analysis flags '.$reason);
 }
 $duplicates=$import->analyze([$row,$row],'test');test(!$duplicates[0]['candidate']&&!$duplicates[1]['candidate']&&in_array('import_duplicate',$duplicates[0]['issues'],true),'both duplicate records blocked');
 $second=$row;$second['subscription_id']='102';test(in_array('import_shared_customer',$import->analyze([$row,$second],'test')[0]['issues'],true),'shared Mollie customer requires combined review');
 blocked(fn()=>$import->csv('email,name'),'import_csv_columns');
 blocked(fn()=>$import->csv(str_replace('source_site','source_site,api_key',$csv)),'import_csv_columns');
 blocked(fn()=>$import->csv($import->serialize(array_fill(0,51,$row))),'import_limit');
 blocked(fn()=>$import->csv(str_repeat('a',Import::MAX_BYTES+1)),'import_invalid_csv');
 blocked(fn()=>$import->csv("\0".$csv),'import_invalid_csv');
 blocked(fn()=>$import->csv("\xff".$csv),'import_invalid_csv');
 blocked(fn()=>$import->csv(str_replace('subscription_id','source_site',$csv)),'import_csv_columns');
 $api=new FakeGateway();$api->payments['tr_original']=['id'=>'tr_original','mode'=>'test','status'=>'paid','customerId'=>'cst_old','mandateId'=>'mdt_old','sequenceType'=>'first','method'=>'ideal','amount'=>['currency'=>'EUR','value'=>'20.00'],'metadata'=>['order_id'=>'100']];
 $api->mandateRows['mdt_old']=['id'=>'mdt_old','customerId'=>'cst_old','status'=>'valid','method'=>'directdebit','masked'=>'•••• 1234'];
 $verified=$import->verify($row,'test',$api);test($verified['masked']==='•••• 1234','existing paid first payment may exceed verification cent');
 $originalPayment=$api->payments['tr_original'];$originalMandate=$api->mandateRows['mdt_old'];
 foreach ([['mode','live','import_payment_mismatch'],['status','failed','import_payment_mismatch'],['customerId','cst_other','import_payment_mismatch'],['method','creditcard','import_payment_mismatch'],['metadata',['order_id'=>'999'],'import_order_mismatch'],['amountChargedBack',['currency'=>'EUR','value'=>'20.00'],'import_payment_reversed'],['amountRefunded',['currency'=>'EUR','value'=>'0.01'],'import_payment_reversed']] as [$key,$value,$reason]) {
  $api->payments['tr_original']=$originalPayment;$api->payments['tr_original'][$key]=$value;blocked(fn()=>$import->verify($row,'test',$api),$reason);
 }
 $api->payments['tr_original']=$originalPayment;
 foreach (['pending','invalid','revoked'] as $status){$api->mandateRows['mdt_old']['status']=$status;blocked(fn()=>$import->verify($row,'test',$api),'mandate_not_valid');}
 $api->mandateRows['mdt_old']=$originalMandate;
 test(!$api->calls&&!$api->subs&&!$api->customers,'analysis and API verification create no financial resources');
 $parent=new MockWooImportOrder(['_mollie_customer_id'=>'cst_old','_mollie_mandate_id'=>'mdt_old','_mollie_payment_id'=>'tr_original','_mollie_payment_mode'=>'test']);$GLOBALS['mi_fixture_parent']=$parent;
 $sub=new MockWooImportSubscription();$sub->dates=['next_payment'=>gmdate('Y-m-d H:i:s',time()+60*DAY_IN_SECONDS)];$GLOBALS['mi_fixture_subscriptions']=[$sub];$before=serialize([$sub,$parent]);
 $woo=$adapter->read(2)[0];test($woo['mollie_customer_id']==='cst_old'&&$woo['mollie_mandate_id']==='mdt_old','HPOS-compatible object getters fall back to parent order');
 test($GLOBALS['mi_fixture_query']['paged']===2&&$GLOBALS['mi_fixture_query']['subscriptions_per_page']===50,'WooCommerce source is paginated');
 test($before===serialize([$sub,$parent]),'WooCommerce source never mutated');
 $sub->period='year';test($adapter->record($sub)['interval']==='12 months','year normalized for Mollie interval');
 $sub->period='month';$sub->interval=3;test($adapter->record($sub)['interval']==='3 months','quarter normalized');
 $sub->dates['trial_end']='2030-01-01 00:00:00';test(in_array('import_trial_end',$adapter->record($sub)['_issues'],true),'trial or end date flagged');
 $sub->manual=true;test(in_array('import_manual_renewal',$adapter->record($sub)['_issues'],true),'manual renewal flagged');
 $sub->gateway='stripe';test(in_array('import_gateway',$adapter->record($sub)['_issues'],true),'other gateway flagged');
 blocked(fn()=>$adapter->read(0),'import_page');
 $plugin=new Plugin(fn()=> $api);$plugin->store=$store;$screen=new ImportScreen($plugin);
 wp_set_current_user(0);$_SERVER['REQUEST_METHOD']='POST';$_POST=[];blocked(fn()=>$screen->authorize('test'),'access_denied');
 $admins=get_users(['role'=>'administrator','number'=>1]);if(!$admins)throw new RuntimeException('Administrator fixture required');wp_set_current_user($admins[0]->ID);
 $_POST=['mode'=>'test','mi_import_nonce'=>'invalid'];blocked(fn()=>$screen->authorize('test'),'csrf_failed');
 $_POST=['mode'=>'live','mi_import_nonce'=>wp_create_nonce('mi_import_test')];blocked(fn()=>$screen->authorize('test'),'csrf_failed');
 $_POST=['mode'=>'test','mi_import_nonce'=>wp_create_nonce('mi_import_test')];$_SERVER['REQUEST_METHOD']='GET';blocked(fn()=>$screen->authorize('test'),'csrf_failed');
 $_SERVER['REQUEST_METHOD']='POST';$screen->authorize('test');test(true,'administrator POST with nonce accepted');
 $attack=$row;$attack['customer_name']='<script>alert(1)</script>';
 $_POST+=['import_source'=>'csv','import_action'=>'analyze','import_page'=>'1','import_csv'=>$import->serialize([$attack])];
 ob_start();$screen->render();$html=ob_get_clean();test(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'CSV cells and retained textarea escaped');
 test(str_contains($html,'Er is nog niets overgezet')&&str_contains($html,'mi_import_nonce'),'preview identifies read-only phase and protects POST');
 test(count($store->rows('customers','test'))===0&&count($store->rows('commands','test'))===0&&count($store->rows('subscriptions','test'))===0,'preview stores no customers, commands or subscriptions');
 $template='<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/common.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/forms.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-includes/css/buttons.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-content/plugins/mollie-incasso/assets/admin.css"><style>body.wp-admin{margin:0;padding:24px;background:#f0f0f1}.mi-admin{margin:0}@media(max-width:782px){body.wp-admin{padding:12px}}</style></head><body class="wp-admin">';
 $_GET=['page'=>'mollie-incasso-import'];ob_start();(new Lukraak\MollieIncasso\Admin($plugin))->render();$view=ob_get_clean();file_put_contents('/tmp/mi-admin-import.html',$template.$view.'</body></html>');
 echo "RESULT $checks import checks passed. No source writes, Mollie requests or persistent personal data.\n";
} finally {
 foreach(['customers','consents','schemes','mandates','commands','payments','subscriptions','tokens','jobs','notifications','limits'] as $name)$store->query('DROP TABLE IF EXISTS '.$store->table($name));
 $_POST=$originalPost;$_GET=$originalGet;$_SERVER=$originalServer;wp_set_current_user($originalUser);FixtureOptions::finish();
}
