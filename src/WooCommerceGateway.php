<?php
namespace Lukraak\MollieIncasso;

final class WooCommerceGateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id='mollie_incasso';$this->has_fields=true;$this->supports=['products'];
        $this->method_title=__('Mollie Incasso — aanmelden','mollie-incasso');
        $this->method_description=__('Eenmalige iDEAL-verificatie voor een gekoppeld abonnementproduct. De API-key en abonnementen beheert u bij Mollie Incasso.','mollie-incasso');
        $this->form_fields=['enabled'=>['title'=>__('Inschakelen','mollie-incasso'),'type'=>'checkbox','label'=>__('Aanmelden via WooCommerce toestaan','mollie-incasso'),'default'=>'yes']];
        $this->init_settings();$this->enabled=$this->get_option('enabled','yes');$this->title=__('iDEAL — abonnement afsluiten','mollie-incasso');
        $this->order_button_text=__('Toestemming geven en € 0,01 betalen','mollie-incasso');
        add_action('woocommerce_update_options_payment_gateways_'.$this->id,[$this,'process_admin_options']);
    }
    public function is_available()
    {
        if($this->enabled!=='yes')return false;
        try{$bridge=WooCommerceBridge::instance();$bridge->contextScheme();$bridge->plugin()->config->assertRun($bridge->mode());$bridge->plugin()->config->key($bridge->mode());$bridge->plugin()->config->webhook($bridge->mode());return true;}catch(Failure $e){return false;}
    }
    public function payment_fields()
    {
        try{$bridge=WooCommerceBridge::instance();$scheme=$bridge->contextScheme();$text=$bridge->plugin()->schemes->disclosure($scheme);$shown=$bridge->plugin()->schemes->displayText($scheme);}
        catch(Failure $e){echo '<p>'.esc_html(Plugin::message($e->reason)).'</p>';return;}
        echo '<div class="mi-woo-disclosure">'.nl2br(esc_html($shown)).'</div><input type="hidden" name="mi_disclosure_hash" value="'.esc_attr(hash('sha256',$text)).'"><p><label for="mi-woo-consent"><input type="checkbox" id="mi-woo-consent" name="mi_consent" value="1" required> '.esc_html($scheme['consent']).'</label></p>';
    }
    public function validate_fields()
    {
        if(($_POST['mi_consent']??'')!=='1'){wc_add_notice(Plugin::message('woo_consent_required'),'error');return false;}return true;
    }
    public function process_payment($order_id)
    {
        try{
            $hash=$_POST['mi_disclosure_hash']??'';
            if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new Failure('scheme_changed');
            return WooCommerceBridge::instance()->checkout((int)$order_id,($_POST['mi_consent']??'')==='1',$hash);
        }catch(Failure $e){wc_add_notice(Plugin::message($e->reason),'error');return ['result'=>'failure','message'=>Plugin::message($e->reason)];}
    }
}
