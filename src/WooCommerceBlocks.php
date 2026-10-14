<?php
namespace Lukraak\MollieIncasso;

final class WooCommerceBlocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
{
    protected $name='mollie_incasso';
    public function initialize() { $this->settings=get_option('woocommerce_mollie_incasso_settings',[]); }
    public function is_active() { return ($this->settings['enabled']??'yes')==='yes'; }
    public function get_payment_method_script_handles()
    {
        wp_register_script('mi-woo-blocks',plugins_url('assets/woocommerce-blocks.js',MOLLIE_INCASSO_FILE),['wc-blocks-registry','wc-settings','wp-element','wp-html-entities'],MOLLIE_INCASSO_VERSION,true);
        return ['mi-woo-blocks'];
    }
    public function get_payment_method_data()
    {
        $available=false;$text='';$consent='';
        try{$bridge=WooCommerceBridge::instance();$scheme=$bridge->contextScheme();$text=$bridge->plugin()->schemes->disclosure($scheme);$consent=$scheme['consent'];$gateway=new WooCommerceGateway();$available=$gateway->is_available();}catch(Failure $e){}
        return ['title'=>__('iDEAL — abonnement afsluiten','mollie-incasso'),'available'=>$available,'disclosure'=>$text,'hash'=>hash('sha256',$text),'consent'=>$consent,'consent_error'=>__('Geef toestemming voor de beschreven incassoafspraak.','mollie-incasso'),'button'=>__('Toestemming geven en € 0,01 betalen','mollie-incasso'),'supports'=>['products']];
    }
}
