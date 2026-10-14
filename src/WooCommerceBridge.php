<?php

namespace Lukraak\MollieIncasso;

/** Optional WooCommerce enrollment, never a second subscription billing engine. */
final class WooCommerceBridge
{
    private static ?self $instance = null;
    private static bool $registered = false;
    public function __construct(private readonly Plugin $plugin) {}
    public static function instance(): self
    {
        if (!self::$instance) throw new Failure('woocommerce_unavailable');
        return self::$instance;
    }
    public function register(): void
    {
        if (!class_exists('WC_Payment_Gateway')) return;
        self::$instance = $this;
        if (self::$registered) return;
        self::$registered = true;
        add_filter('woocommerce_payment_gateways', static function ($gateways) { $gateways[]=WooCommerceGateway::class;return $gateways; });
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) { if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) $registry->register(new WooCommerceBlocks()); });
        add_action('woocommerce_order_status_cancelled', static fn($id)=>self::instance()->cancelPending((int)$id));
        // Stock holding must not withdraw consent while a delayed webhook or cron still has to confirm the €0.01.
        add_filter('woocommerce_cancel_unpaid_order', static fn($cancel,$order)=>is_object($order) && $order->get_payment_method()==='mollie_incasso' ? false : $cancel, 10, 2);
        add_action('mollie_incasso_sync_checkout', static fn($id,$mode)=>self::instance()->sync((int)$id,$mode),10,2);
        add_action('mollie_incasso_cancel_checkout', static fn($order,$mode)=>self::instance()->cancelPending((int)$order,true),10,2);
        add_filter('woocommerce_available_payment_gateways', static fn($gateways)=>self::instance()->availableGateways($gateways));
        add_filter('woocommerce_add_to_cart_validation', static fn($valid,$id,$quantity)=>self::instance()->addToCart($valid,(int)$id,(int)$quantity),10,3);
        add_action('woocommerce_before_calculate_totals', static fn($cart)=>self::instance()->cartPrices($cart));
        add_filter('woocommerce_coupon_is_valid', static function ($valid) { if (self::instance()->hasCartProduct()) throw new \Exception(__('Kortingscodes gelden niet voor de verificatiebetaling van € 0,01.','mollie-incasso'));return $valid; });
        add_filter('woocommerce_get_price_html', static fn($html,$product)=>self::instance()->priceHtml($html,$product),10,2);
        add_action('woocommerce_thankyou_mollie_incasso', static fn($id)=>self::instance()->orderStatus((int)$id));
        // Before the template loads, so WooCommerce itself shows "paid" or its "payment failed" text.
        add_action('template_redirect', static fn()=>self::instance()->refreshReturnedOrder());
        // A failed verification is never retried on the same order: a new order starts a fresh enrollment.
        add_filter('woocommerce_valid_order_statuses_for_payment', static fn($statuses,$order=null)=>is_object($order) && $order->get_payment_method()==='mollie_incasso' ? array_values(array_diff((array)$statuses,['failed'])) : $statuses, 10, 2);
        // Nothing to ship: a paid €0.01 verification order completes immediately.
        add_filter('woocommerce_order_item_needs_processing', static fn($needs,$product)=>is_object($product) && self::instance()->isProduct($product) ? false : $needs, 10, 2);
        add_action('woocommerce_product_options_general_product_data', static fn()=>self::instance()->productFields());
        add_action('woocommerce_admin_process_product_object', static fn($product)=>self::instance()->saveProduct($product));
    }
    public function plugin(): Plugin { return $this->plugin; }
    public function mode(): string { return $this->plugin->config->mode(); }
    public function isProduct(object $product): bool { return (int)$product->get_meta('_mi_scheme_test',true)>0 || (int)$product->get_meta('_mi_scheme_live',true)>0; }
    public function productScheme(object $product): array
    {
        if (!$product->is_type('simple') || !$product->is_virtual()) throw new Failure('woo_product_invalid');
        $id=(int)$product->get_meta('_mi_scheme_'.$this->mode(),true);
        if (!$id) throw new Failure('woo_product_mode');
        $scheme=$this->plugin->schemes->get($id,$this->mode());
        if ($scheme['type']!=='fixed' || empty($scheme['auto_start'])) throw new Failure('woo_scheme_automatic');
        return $scheme;
    }
    public function hasCartProduct(): bool
    {
        if (!function_exists('WC') || !WC()->cart) return false;
        foreach (WC()->cart->get_cart() as $item) if (!empty($item['data']) && $this->isProduct($item['data'])) return true;
        return false;
    }
    public function cartScheme(): array
    {
        if (!function_exists('WC') || !WC()->cart) throw new Failure('woo_cart_invalid');
        $items=WC()->cart->get_cart();
        if (count($items)!==1) throw new Failure('woo_cart_invalid');
        $item=reset($items);
        if ((int)$item['quantity']!==1) throw new Failure('woo_cart_invalid');
        return $this->productScheme($item['data']);
    }
    public function orderScheme(object $order): array
    {
        $items=$order->get_items();
        if (count($items)!==1 || $order->get_currency()!=='EUR' || Money::cents((string)$order->get_total())!==1 || (float)$order->get_total_tax()!==0.0 || (float)$order->get_shipping_total()!==0.0 || (float)$order->get_discount_total()!==0.0 || $order->get_fees()) throw new Failure('woo_order_invalid');
        $item=reset($items);$product=$item->get_product();
        if (!$product || (int)$item->get_quantity()!==1 || Money::cents((string)$item->get_total())!==1) throw new Failure('woo_order_invalid');
        return $this->productScheme($product);
    }
    public function contextScheme(): array
    {
        $pay=(int)get_query_var('order-pay');
        if ($pay && ($order=wc_get_order($pay))) return $this->orderScheme($order);
        return $this->cartScheme();
    }
    public function availableGateways(array $gateways): array
    {
        $special=$this->hasCartProduct();
        $pay=(int)get_query_var('order-pay');
        if ($pay && ($order=wc_get_order($pay))) foreach($order->get_items() as $item) { $product=$item->get_product();if($product && $this->isProduct($product))$special=true; }
        if ($special) return isset($gateways['mollie_incasso']) ? ['mollie_incasso'=>$gateways['mollie_incasso']] : [];
        unset($gateways['mollie_incasso']);
        return $gateways;
    }
    public function addToCart(bool $valid,int $id,int $quantity): bool
    {
        $product=wc_get_product($id);if (!$product) return $valid;
        try {
            if ($this->isProduct($product)) {
                $this->productScheme($product);
                if ($quantity!==1 || function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) throw new Failure('woo_cart_invalid');
            } elseif ($this->hasCartProduct()) throw new Failure('woo_cart_invalid');
            return $valid;
        } catch(Failure $e) { wc_add_notice(Plugin::message($e->reason),'error');return false; }
    }
    public function cartPrices(object $cart): void
    {
        foreach ($cart->get_cart() as $item) if ($this->isProduct($item['data'])) { $item['data']->set_price('0.01');$item['data']->set_tax_status('none'); }
    }
    public function priceHtml(string $html,object $product): string
    {
        if (!$this->isProduct($product)) return $html;
        try { $scheme=$this->productScheme($product); }
        catch(Failure $e) { return esc_html__('Aanmelding niet beschikbaar in deze betaalmodus.','mollie-incasso'); }
        return esc_html(Money::eur((int)$scheme['amount']).' / '.Plugin::interval($scheme['interval'])).'<small style="display:block">'.esc_html__('Eenmalig € 0,01 ter verificatie; dit is geen abonnementsperiode.','mollie-incasso').'</small>';
    }
    public function checkout(int $orderId,bool $accepted,string $disclosureHash): array
    {
        $mode=$this->mode();$this->plugin->config->assertRun($mode,true);
        $this->plugin->config->key($mode);$this->plugin->config->webhook($mode);
        if (!$this->plugin->config->settings()['organization'] || !$this->plugin->config->settings()['contact']) throw new Failure('organization_missing');
        if (!$accepted) throw new Failure('woo_consent_required');
        return $this->plugin->store->lock('woocommerce-order:'.$orderId,function() use($orderId,$mode,$disclosureHash) {
            $order=wc_get_order($orderId);
            if (!$order || $order->get_payment_method()!=='mollie_incasso' || in_array($order->get_status(),['cancelled','refunded','trash'],true)) throw new Failure('woo_order_invalid');
            $existing=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('checkouts').' WHERE order_id=%d',[$orderId]);
            if ($existing && $existing[0]['mode']!==$mode) throw new Failure('environment_mismatch');
            $scheme=$this->orderScheme($order);
            $disclosure=$this->plugin->schemes->disclosure($scheme);
            if (!hash_equals(hash('sha256',$disclosure),$disclosureHash)) throw new Failure('scheme_changed');
            $identity=['name'=>sanitize_text_field(trim($order->get_billing_first_name().' '.$order->get_billing_last_name())),'email'=>strtolower(sanitize_email($order->get_billing_email())),'user_id'=>(int)$order->get_user_id(),'scheme_id'=>$scheme['id']];
            if (!is_email($identity['email']) || !$identity['name']) throw new Failure('invalid_signup');
            if (!$existing) {
                if ($order->is_paid()) throw new Failure('woo_order_invalid');
                $id=$this->plugin->store->insert('checkouts',['mode'=>$mode,'order_id'=>$orderId,'reference'=>wp_generate_uuid4(),'scheme_id'=>$scheme['id'],'email'=>$identity['email'],'payload'=>wp_json_encode($identity+['disclosure_hash'=>$disclosureHash,'disclosure'=>$disclosure,'accepted_at'=>Store::now(),'redirect_url'=>$order->get_checkout_order_received_url()]),'created_at'=>Store::now(),'updated_at'=>Store::now()]);
            } else {
                $receipt=$existing[0];$saved=json_decode($receipt['payload'],true);
                foreach($identity as $key=>$value) if ((string)$saved[$key] !== (string)$value) throw new Failure('woo_order_changed');
                if (!hash_equals($saved['disclosure_hash'],$disclosureHash)) throw new Failure('scheme_changed');
                $id=(int)$receipt['id'];
            }
            try { $enrollment=$this->plugin->service->checkoutSignup($id,$mode); }
            catch(Failure $e) {
                $this->plugin->store->update('checkouts',['error'=>$e->reason,'updated_at'=>Store::now()],$id,$mode);
                if ($e->temporary || $e->unknown) return ['result'=>'success','redirect'=>$order->get_checkout_order_received_url()];
                throw $e;
            }
            $this->plugin->store->update('checkouts',['error'=>'','updated_at'=>Store::now()],$id,$mode);
            $order->update_meta_data('_mi_checkout_mode',$mode);$order->update_meta_data('_mi_customer_id',(int)$enrollment['customer']['id']);$order->save();
            $payment=$enrollment['payment'];
            if ($payment && in_array($payment['status'],['open','pending'],true) && $payment['checkout_url']) return ['result'=>'success','redirect'=>$payment['checkout_url']];
            if ($payment && in_array($payment['status'],['failed','expired','canceled'],true)) throw new Failure('woo_verification_failed');
            return ['result'=>'success','redirect'=>$order->get_checkout_order_received_url()];
        });
    }
    /** $retry: called from the queue; an unknown outcome then throws so the job backs off and stays visible. */
    public function cancelPending(int $orderId, bool $retry = false): void
    {
        $order=wc_get_order($orderId);
        if(!$order || $order->get_status()!=='cancelled' || $order->get_payment_method()!=='mollie_incasso')return;
        $rows=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('checkouts').' WHERE order_id=%d',[$orderId]);
        foreach($rows as$row){
            $mode=$row['mode'];$cid=(int)$row['customer_id'];
            if(!$cid){$this->plugin->store->update('checkouts',['state'=>'canceled','updated_at'=>Store::now()],(int)$row['id'],$mode);continue;}
            // A completed verification order is not the ongoing subscription contract.
            if($order->get_date_paid())continue;
            // The local status can lag behind Mollie: ask Mollie before treating the signup as abandoned.
            $state=$this->plugin->service->firstPaymentState($cid,$mode);
            if($state==='paid'){
                $order->add_order_note(__('De verificatiebetaling is bij Mollie betaald. De toestemming blijft actief; annuleren van deze bestelling beëindigt geen abonnement. Gebruik daarvoor Mollie Incasso.','mollie-incasso'));
                continue;
            }
            if($state==='unknown'){
                // Never withdraw on an unverified outcome: keep consent and decide after a new check.
                $this->plugin->store->update('checkouts',['error'=>'cancel_outcome_unknown','updated_at'=>Store::now()],(int)$row['id'],$mode);
                if($retry)throw new Failure('cancel_outcome_unknown',true);
                $order->add_order_note(Plugin::message('cancel_outcome_unknown'));
                $this->plugin->queue->put($mode,'cancel_checkout','cancel-checkout:'.$orderId,['order'=>$orderId],gmdate('Y-m-d H:i:s',time()+300));
                continue;
            }
            $this->plugin->store->lock('customer:'.$mode.':'.$cid,function()use($row,$mode,$cid){
                $this->plugin->store->transaction(function()use($row,$mode,$cid){
                    $this->plugin->store->update('checkouts',['state'=>'canceled','updated_at'=>Store::now()],(int)$row['id'],$mode);
                    $this->plugin->store->update('customers',['status'=>'revoking','updated_at'=>Store::now()],$cid,$mode);
                    $this->plugin->store->query('UPDATE '.$this->plugin->store->table('consents').' SET withdrawn_at=COALESCE(withdrawn_at,%s) WHERE customer_id=%d AND mode=%s',[Store::now(),$cid,$mode]);
                    $this->plugin->store->query('UPDATE '.$this->plugin->store->table('commands').' SET state="canceled",error="consent_withdrawn" WHERE customer_id=%d AND mode=%s AND attempted_at IS NULL AND state IN ("draft","scheduled")',[$cid,$mode]);
                    $this->plugin->queue->put($mode,'withdraw','withdraw:'.$cid,['id'=>$cid]);
                });
            });
        }
    }
    public function sync(int $customerId,string $mode): void
    {
        if ($mode!==$this->mode()) throw new Failure('environment_mismatch');
        $rows=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('checkouts').' WHERE customer_id=%d AND mode=%s',[$customerId,$mode]);
        foreach($rows as $receipt) {
            if($receipt['state']==='canceled')continue;
            $order=wc_get_order((int)$receipt['order_id']);
            if (!$order) throw new Failure('woo_order_missing');
            if ($order->get_payment_method()!=='mollie_incasso' || Money::cents((string)$order->get_total())!==1 || $order->get_currency()!=='EUR') throw new Failure('woo_order_changed');
            $payments=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('payments').' WHERE customer_id=%d AND mode=%s AND kind="first" ORDER BY id DESC LIMIT 1',[$customerId,$mode]);
            if (!$payments) continue;
            $payment=$payments[0];
            if ($payment['status']==='paid' && !(int)$payment['refunded'] && !(int)$payment['charged_back']) {
                if (!$order->is_paid() && !in_array($order->get_status(),['cancelled','refunded'],true)) $order->payment_complete($payment['remote_id']);
                $this->plugin->store->update('checkouts',['state'=>'complete','error'=>'','updated_at'=>Store::now()],(int)$receipt['id'],$mode);
            } elseif(in_array($payment['status'],['failed','expired','canceled'],true)) {
                if (!$order->is_paid() && $order->get_status()!=='failed') $order->update_status('failed',__('De verificatiebetaling is niet geslaagd. Er is geen abonnement gestart.','mollie-incasso'));
                $this->plugin->store->update('checkouts',['state'=>'failed','updated_at'=>Store::now()],(int)$receipt['id'],$mode);
            } elseif((int)$payment['refunded'] || (int)$payment['charged_back']) {
                if ($order->get_status()!=='on-hold') $order->update_status('on-hold',__('De verificatiebetaling is terugbetaald of gestorneerd. Controleer de incassotoestemming.','mollie-incasso'));
            }
        }
    }
    /** Order-received page of an enrollment order: ask Mollie (rate-limited) and update the order first. */
    public function refreshReturnedOrder(): void
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) return;
        $id=(int)get_query_var('order-received');$order=$id?wc_get_order($id):null;
        if(!$order || $order->get_payment_method()!=='mollie_incasso')return;
        $key=isset($_GET['key']) && is_string($_GET['key']) ? wp_unslash($_GET['key']) : '';
        if (!$key || !hash_equals($order->get_order_key(),$key)) return;
        $rows=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('checkouts').' WHERE order_id=%d AND mode=%s',[$id,$this->mode()]);
        if(!$rows || !$rows[0]['customer_id'] || $rows[0]['state']!=='pending')return;
        try {
            $this->plugin->store->limit('return-sync:'.$rows[0]['customer_id'],10,600);
            if($this->plugin->service->firstPaymentState((int)$rows[0]['customer_id'],$this->mode(),true)!=='unknown') $this->sync((int)$rows[0]['customer_id'],$this->mode());
        } catch(Failure $e) {}
    }
    public function orderStatus(int $id): void
    {
        $order=wc_get_order($id);if(!$order)return;
        $key=isset($_GET['key']) && is_string($_GET['key']) ? wp_unslash($_GET['key']) : '';
        if (!($order->get_user_id() && (int)$order->get_user_id()===get_current_user_id()) && (!$key || !hash_equals($order->get_order_key(),$key))) return;
        $rows=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('checkouts').' WHERE order_id=%d AND mode=%s',[$id,$this->mode()]);if(!$rows)return;
        $row=$rows[0];
        echo '<section class="mi-woo-status"><h2>'.esc_html__('Uw incassoaanmelding','mollie-incasso').'</h2><p>'.esc_html__('Deze bestelling betreft alleen de verificatiebetaling van € 0,01. Uw vaste abonnement en volgende incasso’s worden beheerd via Mollie Incasso.','mollie-incasso').'</p>';
        if($row['customer_id']) {
            $customer=$this->plugin->service->customer((int)$row['customer_id'],$this->mode());
            echo '<p>'.esc_html(Plugin::status($customer['status'])).'</p>';
            if($customer['status']==='verification_failed') echo '<p>'.esc_html__('Er is geen abonnement gestart en er wordt niets geïncasseerd. Wilt u het opnieuw proberen? Plaats dan een nieuwe bestelling van dit abonnement.','mollie-incasso').'</p>';
            $payments=$this->plugin->store->select('SELECT * FROM '.$this->plugin->store->table('payments').' WHERE customer_id=%d AND mode=%s AND kind="first" ORDER BY id DESC LIMIT 1',[(int)$row['customer_id'],$this->mode()]);
            if($payments && in_array($payments[0]['status'],['open','pending'],true) && $payments[0]['checkout_url']) echo '<p><a class="button" href="'.esc_url($payments[0]['checkout_url']).'">'.esc_html__('Verificatie via iDEAL afronden','mollie-incasso').'</a></p>';
        } else echo '<p>'.esc_html__('Uw betaling wordt voorbereid. Vernieuw deze pagina later; maak geen tweede bestelling aan.','mollie-incasso').'</p>';
        echo '</section>';
    }
    public function createProduct(int $schemeId): int
    {
        if (!current_user_can('manage_options') || !current_user_can('edit_products')) throw new Failure('access_denied');
        $scheme=$this->plugin->schemes->get($schemeId,$this->mode());
        if($scheme['type']!=='fixed' || empty($scheme['auto_start']))throw new Failure('woo_scheme_automatic');
        return $this->plugin->store->lock('woo-product:'.$this->mode().':'.$schemeId,function()use($scheme,$schemeId){
            $option='mi_woo_product_'.$this->mode().'_'.$schemeId;$existing=(int)get_option($option);
            if($existing && ($product=wc_get_product($existing)) && $product->get_status()!=='trash')return $existing;
            $product=new \WC_Product_Simple();$product->set_name($scheme['name']);$product->set_status('draft');$product->set_virtual(true);$product->set_sold_individually(true);$product->set_regular_price('0.01');$product->set_tax_status('none');
            $product->set_description($this->plugin->schemes->disclosure($scheme));$product->set_short_description(__('Eenmalig € 0,01 verificatie via iDEAL. Daarna het vaste bedrag volgens de incassoafspraak.','mollie-incasso'));
            $product->update_meta_data('_mi_scheme_'.$this->mode(),$schemeId);$id=$product->save();if(!$id)throw new Failure('woo_product_failed');update_option($option,$id,false);return $id;
        });
    }
    public function productFields(): void
    {
        if(!current_user_can('manage_options'))return;
        echo '<div class="options_group"><p class="form-field">'.esc_html__('Optionele incassoaanmelding: gebruik een eenvoudig virtueel product. Het afrekenbedrag is € 0,01; vervolgbetalingen lopen via Mollie Incasso.','mollie-incasso').'</p>';
        foreach(['test','live']as$mode){$options=[''=>__('Niet gekoppeld','mollie-incasso')];foreach($this->plugin->store->rows('schemes',$mode,500)as$row){$data=json_decode($row['data'],true);if($row['active']&&$data['type']==='fixed'&&!empty($data['auto_start']))$options[$row['id']]=$data['name'];}woocommerce_wp_select(['id'=>'_mi_scheme_'.$mode,'label'=>sprintf(__('Incassoregeling (%s)','mollie-incasso'),$mode),'options'=>$options]);}echo '</div>';
    }
    public function saveProduct(object $product): void
    {
        if(!current_user_can('manage_options'))return;
        foreach(['test','live']as$mode){$raw=$_POST['_mi_scheme_'.$mode]??null;if($raw===null)continue;if(!is_scalar($raw))throw new Failure('scheme_unavailable');$id=absint($raw);if($id){$scheme=$this->plugin->schemes->get($id,$mode);if($scheme['type']!=='fixed'||empty($scheme['auto_start']))throw new Failure('woo_scheme_automatic');}$product->update_meta_data('_mi_scheme_'.$mode,$id);}
    }
}
