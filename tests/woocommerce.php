<?php
// Real WooCommerce CRUD and checkout gateway; fake Mollie API and intercepted mail.
define('WP_INSTALLING',true);
define('MOLLIE_INCASSO_TEST_KEY','test_MOCKKEYONLY000000000000000000000000');
require getenv('MI_WP_ROOT') ?: '/var/www/html/wp-load.php';
require __DIR__.'/FixtureOptions.php';
use Lukraak\MollieIncasso\Tests\FixtureOptions;
FixtureOptions::start();
FixtureOptions::scope('woocommerce_custom_orders_table_enabled',getenv('MI_WOO_HPOS')==='1'?'yes':'no');
FixtureOptions::scope('woocommerce_custom_orders_table_data_sync_enabled','no');
FixtureOptions::scope('woocommerce_mollie_incasso_settings',[]);
add_filter('pre_wp_mail',static fn()=>true);
require WP_PLUGIN_DIR.'/woocommerce/woocommerce.php';
WC_Post_Types::register_taxonomies();WC_Post_Types::register_post_types();WC()->init();WC()->initialize_session();WC()->initialize_cart();
require dirname(__DIR__).'/mollie-incasso.php';
require __DIR__.'/FakeGateway.php';
use Lukraak\MollieIncasso\{Plugin,Store,Config,Schemes,Tokens,Queue,Notifications,Service,WooCommerceBridge,WooCommerceGateway,WooCommerceBlocks,Failure};
use Lukraak\MollieIncasso\Tests\FakeGateway;
$checks=0;$products=[];$orders=[];
function ok(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function deny(callable $call,string $reason):void{try{$call();}catch(Failure $e){ok($e->reason===$reason,'blocked '.$reason);return;}throw new RuntimeException('Expected '.$reason);}
$db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$db->set_prefix('mi_woo_test_'.bin2hex(random_bytes(4)).'_');$store=new Store($db);$store->install();
$config=new Config();FixtureOptions::set('mi_settings',['mode'=>'test','organization'=>'Testorganisatie','contact'=>'test@example.invalid','address'=>'Testadres','webhook_url'=>'https://example.org/webhook']);FixtureOptions::set('mi_site_binding',$config->binding());FixtureOptions::set('mi_instance',wp_generate_uuid4());
$api=new FakeGateway();$schemes=new Schemes($store,$config);$tokens=new Tokens($store);$queue=new Queue($store,$config);$mail=new Notifications($store,$config,$tokens,$queue);$service=new Service($store,$config,$schemes,$tokens,$queue,$mail,fn()=>$api);
$p=new Plugin(fn()=>$api);$p->store=$store;$p->config=$config;$p->schemes=$schemes;$p->tokens=$tokens;$p->queue=$queue;$p->mail=$mail;$p->service=$service;
$bridge=new WooCommerceBridge($p);$bridge->register();
$oldUser=get_current_user_id();$oldPost=$_POST;$oldGet=$_GET;
$admins=get_users(['role'=>'administrator','number'=>1]);wp_set_current_user($admins[0]->ID);
function orderFor($product,string $amount='0.01',string $gateway='mollie_incasso'){
 global $orders;
 $o=wc_create_order(['created_via'=>'mollie_incasso_fixture']);$orders[]=$o->get_id();$o->set_currency('EUR');$o->set_payment_method($gateway);$o->set_billing_first_name('Voorbeeld');$o->set_billing_last_name('Klant');$o->set_billing_email('test@example.invalid');$o->add_product($product,1,['subtotal'=>$amount,'total'=>$amount]);$o->calculate_totals(false);$o->save();return $o;
}
try{
 ok(!function_exists('wcs_get_subscriptions'),'no WooCommerce Subscriptions dependency');
 $input=['name'=>'GCC doorlopend abonnement','type'=>'fixed','auto_start'=>'1','amount'=>'20.00','interval'=>'1 month','purpose'=>'Lidmaatschap GCC','calculation'=>'Vast bedrag per periode','frequency'=>'Maandelijks','withdrawal'=>'Via uw persoonlijke statuslink','consent'=>'Ik geef toestemming voor dit vaste abonnement.','notice_days'=>14];
 $schemeId=$schemes->save($input,'test');$scheme=$schemes->get($schemeId,'test');
 ok($scheme['auto_start']===true,'automatic subscription enabled explicitly');
 ok(str_contains($schemes->disclosure($scheme),'start uw vaste abonnement automatisch'),'automatic start included in consent disclosure');
 FixtureOptions::scope('mi_woo_product_test_'.$schemeId,0);
 $pid=$bridge->createProduct($schemeId);$products[]=$pid;$product=wc_get_product($pid);
 ok($product->get_status()==='draft'&&$product->is_virtual()&&$product->is_sold_individually()&&$product->get_regular_price()==='0.01','helper creates a virtual single-item verification product draft');
 ok($bridge->createProduct($schemeId)===$pid,'repeated helper click reuses product');
 ok((int)$product->get_meta('_mi_scheme_test')===$schemeId,'product linked to exact scheme and mode');
 $second=$input;$second['name']='GCC jaarabonnement';$second['interval']='12 months';$second['amount']='200.00';$secondId=$schemes->save($second,'test');FixtureOptions::scope('mi_woo_product_test_'.$secondId,0);$pid2=$bridge->createProduct($secondId);$products[]=$pid2;
 ok($pid2!==$pid && $schemes->get($schemeId,'test')['amount']===2000,'multiple plans have independent products and preserve existing plan');
 $manual=$input;$manual['type']='manual';$manualId=$schemes->save($manual,'test');deny(fn()=>$bridge->createProduct($manualId),'woo_scheme_automatic');
 $product->set_status('publish');$product->save();
 WC()->cart->empty_cart();$cartKey=WC()->cart->add_to_cart($pid);ok((bool)$cartKey,'virtual subscription added to real WooCommerce cart');WC()->cart->calculate_totals();
 ok($bridge->cartScheme()['id']===$schemeId && abs((float)WC()->cart->get_total('edit')-.01)<.00001,'cart uses verification cent and server-side plan');
 ok(str_contains($bridge->priceHtml('', $product),'20')&&str_contains($bridge->priceHtml('', $product),'geen abonnementsperiode'),'product shows recurring amount and separate verification');
 $gateway=new WooCommerceGateway();ok($gateway->is_available(),'enrollment gateway available for linked cart');
 $gateways=$bridge->availableGateways(['mollie_incasso'=>$gateway,'other'=>(object)[]]);ok(array_keys($gateways)===['mollie_incasso'],'one-off gateways hidden for enrollment cart');
 $normal=new WC_Product_Simple();$normal->set_name('Fixture normal');$normal->set_status('publish');$normal->set_regular_price('10.00');$normalId=$normal->save();$products[]=$normalId;
 ok(!$bridge->addToCart(true,$normalId,1),'ordinary products cannot mix with enrollment');wc_clear_notices();
 ob_start();$gateway->payment_fields();$fields=ob_get_clean();ok(str_contains($fields,'name="mi_consent"')&&!str_contains($fields,'checked')&&str_contains($fields,'mi_disclosure_hash'),'classic checkout has explicit unchecked consent and disclosure');
 $_POST=[];ok(!$gateway->validate_fields(),'classic checkout rejects missing consent');wc_clear_notices();
 $blocks=new WooCommerceBlocks();$blocks->initialize();$blockData=$blocks->get_payment_method_data();ok($blocks->is_active()&&$blockData['available']&&$blockData['hash']===hash('sha256',$schemes->disclosure($scheme)),'Blocks publishes same terms and hash without a key');
 ok(!str_contains(wp_json_encode($blockData),MOLLIE_INCASSO_TEST_KEY),'Blocks never receives API key');
 $blocks->get_payment_method_script_handles();ok(wp_script_is('mi-woo-blocks','registered'),'Blocks integration registers its script');
 $order=orderFor($product);$hash=hash('sha256',$schemes->disclosure($scheme));
 deny(fn()=>$bridge->checkout($order->get_id(),false,$hash),'woo_consent_required');deny(fn()=>$bridge->checkout($order->get_id(),true,str_repeat('0',64)),'scheme_changed');
 $bad=orderFor($product,'20.00');deny(fn()=>$bridge->checkout($bad->get_id(),true,$hash),'woo_order_invalid');
 $badGateway=orderFor($product,'0.01','other');deny(fn()=>$bridge->checkout($badGateway->get_id(),true,$hash),'woo_order_invalid');
 ok(!$api->calls,'invalid checkouts make no API resources');
 $result=$bridge->checkout($order->get_id(),true,$hash);$again=$bridge->checkout($order->get_id(),true,$hash);
 ok($result===$again&&str_starts_with($result['redirect'],'https://www.mollie.com/checkout/'),'checkout redirects to existing Mollie first-payment URL');
 ok(($api->calls['customer']??0)===1&&($api->calls['payment']??0)===1,'repeated checkout creates exactly one customer and first payment');
 $receipts=$store->rows('checkouts','test');$receipt=$receipts[0];$cid=(int)$receipt['customer_id'];$customer=$service->customer($cid,'test');$payment=$store->rows('payments','test')[0];
 ok($api->payments[$payment['remote_id']]['sequenceType']==='first'&&$api->payments[$payment['remote_id']]['method']==='ideal'&&$api->payments[$payment['remote_id']]['amount']['value']==='0.01','checkout creates first ideal payment with EUR 0.01');
 ok(!$api->subs&&$customer['status']==='pending','browser checkout does not activate a subscription');
 $bridge->sync($cid,'test');ok(!wc_get_order($order->get_id())->is_paid(),'order remains unpaid before API proof');
 $order->set_billing_email('changed@example.invalid');$order->save();deny(fn()=>$bridge->checkout($order->get_id(),true,$hash),'woo_order_changed');$order->set_billing_email('test@example.invalid');$order->save();
 $api->payments[$payment['remote_id']]['status']='paid';$service->syncPayment($payment['remote_id'],'test');
 $bridge->sync($cid,'test');$bridge->sync($cid,'test');$paidOrder=wc_get_order($order->get_id());
 ok($paidOrder->is_paid()&&$paidOrder->get_transaction_id()===$payment['remote_id'],'API-paid verification completes WooCommerce order idempotently');
 $api->mandateRows['mdt_fixture']=['id'=>'mdt_fixture','customerId'=>$customer['remote_id'],'status'=>'pending','method'=>'directdebit','masked'=>'•••• 1234'];
 deny(fn()=>$service->refreshMandate($cid,'test'),'mandate_not_valid');deny(fn()=>$service->startAutomaticSubscription($cid,'test'),'customer_not_active');ok(!$api->subs,'pending mandate never starts subscription');
 $api->mandateRows['mdt_fixture']['status']='valid';$service->refreshMandate($cid,'test');$service->startAutomaticSubscription($cid,'test');$service->startAutomaticSubscription($cid,'test');
 $commands=$store->select('SELECT * FROM '.$store->table('commands').' WHERE kind="subscription" AND customer_id=%d',[$cid]);ok(count($commands)===1&&$commands[0]['state']==='scheduled','valid mandate prepares and confirms one automatic subscription command');
 $auto=$commands[0];$payload=json_decode($auto['payload'],true);ok($payload['date']>wp_date('Y-m-d',time()+14*DAY_IN_SECONDS)&&$auto['amount']==2000,'first period starts after agreed notice and uses recurring amount');
 $pre=$store->select('SELECT * FROM '.$store->table('notifications').' WHERE event_key=%s',['pre:command:'.$auto['id']])[0];$mail->send((int)$pre['id'],'test');$service->execute((int)$auto['id'],'test');$service->startAutomaticSubscription($cid,'test');
 ok(count($api->subs)===1&&($api->calls['subscription']??0)===1,'repeated automatic jobs produce exactly one Mollie Subscription');
 $subscription=reset($api->subs);ok($subscription['amount']['value']==='20.00'&&$subscription['interval']==='1 month'&&$subscription['startDate']===$payload['date'],'Mollie subscription receives exact amount, interval, and future start');
 // Genuine gateway process_payment path used by classic and Blocks legacy processing.
 $_POST=['mi_consent'=>'1','mi_disclosure_hash'=>$hash];$processed=$gateway->process_payment($order->get_id());ok($processed['result']==='success'&&($api->calls['payment']??0)===1,'gateway and Blocks legacy retries reuse the payment');
 $context=new Automattic\WooCommerce\StoreApi\Payments\PaymentContext();$context->set_payment_method('mollie_incasso');$context->set_payment_data(['mi_consent'=>'1','mi_disclosure_hash'=>$hash]);$context->set_order(wc_get_order($order->get_id()));
 $paymentResult=new Automattic\WooCommerce\StoreApi\Payments\PaymentResult();(new Automattic\WooCommerce\StoreApi\Legacy())->process_legacy_payment($context,$paymentResult);
 ok($paymentResult->status==='success'&&($api->calls['payment']??0)===1,'real Store API legacy processor calls gateway without duplicate payment');
 $header='<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.6 system-ui;margin:0;padding:24px;color:#253b49}main{max-width:640px;margin:auto}.mi-woo-disclosure{white-space:normal;font-size:14px}input[type=checkbox]{width:20px;height:20px}button{padding:12px 20px;background:#126653;color:white;border:0;border-radius:6px}label{display:flex;gap:10px}</style></head><body><main>';
 file_put_contents('/tmp/mi-woo-classic.html',$header.'<h1>GCC doorlopend abonnement</h1>'.$bridge->priceHtml('',$product).'<form>'.$fields.'<button type="submit">Toestemming geven en € 0,01 betalen</button></form></main></body></html>');
 $settings=wp_json_encode($blockData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
 $scripts='<script src="http://localhost:8008/wp-includes/js/dist/vendor/react.min.js"></script><script src="http://localhost:8008/wp-includes/js/dist/vendor/react-dom.min.js"></script><script src="http://localhost:8008/wp-includes/js/dist/escape-html.min.js"></script><script src="http://localhost:8008/wp-includes/js/dist/element.min.js"></script><script src="http://localhost:8008/wp-includes/js/dist/html-entities.min.js"></script>';
 $boot='<script>window.wc={wcSettings:{getSetting:function(){return '.$settings.';}},wcBlocksRegistry:{registerPaymentMethod:function(method){window.miMethod=method;}}};</script><script src="http://localhost:8008/wp-content/plugins/mollie-incasso/assets/woocommerce-blocks.js"></script><script>window.miPayload=null;var root=wp.element.createRoot(document.getElementById("root"));root.render(wp.element.cloneElement(window.miMethod.content,{eventRegistration:{onPaymentSetup:function(fn){window.miSubmit=fn;return function(){};}},emitResponse:{responseTypes:{SUCCESS:"success",ERROR:"error"}}}));</script>';
 file_put_contents('/tmp/mi-woo-blocks.html',$header.'<h1>GCC doorlopend abonnement</h1><div id="root"></div></main>'.$scripts.$boot.'</body></html>');
 $_GET=['key'=>'wrong'];wp_set_current_user(0);ob_start();$bridge->orderStatus($order->get_id());ok(ob_get_clean()==='','order status cannot be read with wrong guest key');
 $_GET=['key'=>$order->get_order_key()];ob_start();$bridge->orderStatus($order->get_id());$status=ob_get_clean();ok(str_contains($status,'Uw incassoaanmelding'),'correct WooCommerce order key grants status only');
 // A timeout after remote creation is not presented as a reason to place a second order.
 wp_set_current_user($admins[0]->ID);$timeoutOrder=orderFor($product);$api->timeout=true;$pending=$bridge->checkout($timeoutOrder->get_id(),true,$hash);ok($pending['result']==='success'&&str_contains($pending['redirect'],'key='),'uncertain create redirects to pending order status');
 $resumed=$bridge->checkout($timeoutOrder->get_id(),true,$hash);ok(str_starts_with($resumed['redirect'],'https://www.mollie.com/checkout/')&&($api->calls['customer']??0)===2,'timeout retry reconciles one customer for the second order');
 // No email-based re-use even when two orders use the same billing email.
 ok(count($store->rows('customers','test'))===2,'same email in another order never adopts an existing mandate');
 // Automatic website form path has the same opt-in and recurring behavior.
 $token=$tokens->issue('test','signup',0,['scheme_id'=>$secondId,'disclosure'=>$schemes->disclosure($schemes->get($secondId,'test'))],hash('sha256','form'),HOUR_IN_SECONDS);
 $web=$service->signup('test',$token,hash('sha256','form'),'Website Klant','website@example.invalid',true);ok($web['payment']['amount']==1,'standalone form remains available without shopping-cart dependency');
 $wpayment=$web['payment'];$wcustomer=$web['customer'];$api->payments[$wpayment['remote_id']]['status']='paid';$service->syncPayment($wpayment['remote_id'],'test');$api->mandateRows['mdt_website']=['id'=>'mdt_website','customerId'=>$wcustomer['remote_id'],'status'=>'valid','method'=>'directdebit'];$service->refreshMandate((int)$wcustomer['id'],'test');$service->startAutomaticSubscription((int)$wcustomer['id'],'test');
 $webCommands=$store->select('SELECT * FROM '.$store->table('commands').' WHERE customer_id=%d AND kind="subscription"',[(int)$wcustomer['id']]);ok(count($webCommands)===1&&json_decode($webCommands[0]['payload'],true)['interval']==='12 months','standalone form automatically schedules chosen annual plan');
 $failedOrder=orderFor($product);$failedEnrollment=$bridge->checkout($failedOrder->get_id(),true,$hash);$failedReceipt=$store->select('SELECT * FROM '.$store->table('checkouts').' WHERE order_id=%d',[$failedOrder->get_id()])[0];$failedPayment=$store->select('SELECT * FROM '.$store->table('payments').' WHERE customer_id=%d',[(int)$failedReceipt['customer_id']])[0];$api->payments[$failedPayment['remote_id']]['status']='failed';$service->syncPayment($failedPayment['remote_id'],'test');$bridge->sync((int)$failedReceipt['customer_id'],'test');ok(wc_get_order($failedOrder->get_id())->get_status()==='failed','failed first payment appears on WooCommerce order without starting subscription');
 $countBefore=$api->calls;deny(fn()=>$bridge->checkout($failedOrder->get_id(),true,$hash),'woo_verification_failed');ok($countBefore===$api->calls,'retry of failed verification does not create another payment silently');
 $beforeMode=$store->rows('checkouts','test')[0];$store->update('checkouts',['mode'=>'live'],(int)$beforeMode['id'],'test');deny(fn()=>$bridge->checkout((int)$beforeMode['order_id'],true,$hash),'environment_mismatch');$store->update('checkouts',['mode'=>'test'],(int)$beforeMode['id'],'live');
 $cancelOrder=orderFor($product);$bridge->checkout($cancelOrder->get_id(),true,$hash);$cancelReceipt=$store->select('SELECT * FROM '.$store->table('checkouts').' WHERE order_id=%d',[$cancelOrder->get_id()])[0];$cancelCustomer=(int)$cancelReceipt['customer_id'];$cancelPayment=$store->select('SELECT * FROM '.$store->table('payments').' WHERE customer_id=%d',[$cancelCustomer])[0];
 $cancelOrder->update_status('cancelled');ok($service->customer($cancelCustomer,'test')['status']==='revoking','cancelling an unpaid order blocks consent locally and queues withdrawal');
 $api->payments[$cancelPayment['remote_id']]['status']='paid';$service->syncPayment($cancelPayment['remote_id'],'test');deny(fn()=>$service->startAutomaticSubscription($cancelCustomer,'test'),'consent_withdrawn');$bridge->sync($cancelCustomer,'test');ok(wc_get_order($cancelOrder->get_id())->get_status()==='cancelled','late paid webhook cannot complete canceled enrollment order');
 // Stock holding never auto-cancels an enrollment order; ordinary gateways keep WooCommerce behaviour.
 $bacsOrder=orderFor($product,'0.01','bacs');ok(apply_filters('woocommerce_cancel_unpaid_order',true,$cancelOrder)===false&&apply_filters('woocommerce_cancel_unpaid_order',true,$bacsOrder)===true,'unpaid-order cleanup skips only enrollment orders');
 // Cancelling while Mollie already has the €0.01 paid (webhook/cron still pending) keeps the consent.
 $paidOrder=orderFor($product);$bridge->checkout($paidOrder->get_id(),true,$hash);$paidReceipt=$store->select('SELECT * FROM '.$store->table('checkouts').' WHERE order_id=%d',[$paidOrder->get_id()])[0];$paidCustomer=(int)$paidReceipt['customer_id'];$paidPayment=$store->select('SELECT * FROM '.$store->table('payments').' WHERE customer_id=%d',[$paidCustomer])[0];
 $api->payments[$paidPayment['remote_id']]['status']='paid';$paidOrder->update_status('cancelled');
 ok($service->customer($paidCustomer,'test')['status']!=='revoking'&&$store->find('payments','remote_id',$paidPayment['remote_id'],'test')['status']==='paid','cancelling an order whose verification Mollie already reports paid keeps the consent');
 $notes=wc_get_order_notes(['order_id'=>$paidOrder->get_id()]);ok((bool)array_filter($notes,fn($n)=>str_contains($n->content,'bij Mollie betaald')),'order note explains the consent stays active');
 $privacy=new Lukraak\MollieIncasso\Privacy($p);$orphan=$store->insert('checkouts',['mode'=>'test','order_id'=>999999999,'reference'=>wp_generate_uuid4(),'scheme_id'=>$schemeId,'email'=>'orphan@example.invalid','payload'=>wp_json_encode(['name'=>'Orphan','email'=>'orphan@example.invalid','accepted_at'=>Store::now(),'disclosure'=>'Afspraken','redirect_url'=>'secret-order-key']),'created_at'=>Store::now(),'updated_at'=>Store::now()]);
 $export=$privacy->exportCheckouts('orphan@example.invalid');ok(count($export['data'])===1&&!str_contains(wp_json_encode($export),'secret-order-key'),'unfinished checkout privacy export excludes private order redirect');
 ok($privacy->eraseCheckouts('orphan@example.invalid')['items_removed']&&!$store->one('checkouts',$orphan,'test'),'unfinished checkout can be safely erased before any customer/API mutation');
 // Wizard creates multiple independent plans and treats repeated submission idempotently.
 $admin=new Lukraak\MollieIncasso\Admin($p);$method=new ReflectionMethod($admin,'wizardPlan');$ref=wp_generate_uuid4();FixtureOptions::scope('mi_wizard_plan_test_'.$ref,[]);$_POST=['plan_reference'=>$ref,'name'=>'Extra plan','amount'=>'30.00','interval'=>'3 months','purpose'=>'Extra lidmaatschap','withdrawal'=>'Via statuslink','consent'=>'Ik ga akkoord.'];
 $newPlan=$method->invoke($admin,$_POST,'test');ok($newPlan===$method->invoke($admin,$_POST,'test')&&$schemes->get($newPlan,'test')['auto_start'],'wizard repeated submit creates one automatic plan');
 ok($schemes->get($schemeId,'test')['amount']===2000&&$schemes->get($secondId,'test')['amount']===20000,'adding plan leaves existing monthly and yearly plans intact');
 WC()->cart->empty_cart();ok(!isset($bridge->availableGateways(['mollie_incasso'=>$gateway,'other'=>(object)[]])['mollie_incasso']),'gateway hidden on ordinary carts');
 $hpos=Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();ok($hpos===(getenv('MI_WOO_HPOS')==='1'),'requested WooCommerce storage mode actually used');
 echo 'RESULT '.$checks.' WooCommerce checks passed; storage='.($hpos?'HPOS':'posts').'; no Mollie network requests or delivered mail.'."\n";
}finally{
 WC()->cart->empty_cart();foreach($orders as$id){$order=wc_get_order($id);if($order)$order->delete(true);}foreach($products as$id){$product=wc_get_product($id);if($product)$product->delete(true);else wp_delete_post($id,true);}
 foreach(['customers','consents','schemes','mandates','commands','payments','subscriptions','tokens','jobs','notifications','limits','checkouts']as$name)$store->query('DROP TABLE IF EXISTS '.$store->table($name));
 $_POST=$oldPost;$_GET=$oldGet;wp_set_current_user($oldUser);FixtureOptions::finish();
}
