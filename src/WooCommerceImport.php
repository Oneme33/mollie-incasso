<?php

namespace Lukraak\MollieIncasso;

/** Optional source adapter. Uses supported object getters, also with HPOS. */
final class WooCommerceImport
{
    public function available(): bool { return function_exists('wcs_get_subscriptions') && function_exists('wc_get_order'); }

    public function read(int $page): array
    {
        if (!$this->available()) throw new Failure('import_woocommerce_missing');
        if ($page<1 || $page>10000) throw new Failure('import_page');
        $subscriptions=wcs_get_subscriptions(['subscriptions_per_page'=>Import::LIMIT,'paged'=>$page,'orderby'=>'ID','order'=>'ASC','subscription_status'=>'any']);
        $rows=[];
        foreach ($subscriptions as $subscription) $rows[]=$this->record($subscription);
        return $rows;
    }

    public function record(object $subscription): array
    {
        $parent=wc_get_order($subscription->get_parent_id());
        $meta=static function(string $name) use ($subscription,$parent): string {
            return (string)($subscription->get_meta($name,true) ?: ($parent ? $parent->get_meta($name,true) : ''));
        };
        $period=$subscription->get_billing_period();$interval=(int)$subscription->get_billing_interval();
        $frequency=match (true) { $period==='month' && $interval===1=>'1 month', $period==='month' && $interval===3=>'3 months', $period==='year' && $interval===1 || $period==='month' && $interval===12=>'12 months', default=>$interval.' '.$period };
        $issues=[];
        if (!str_starts_with((string)$subscription->get_payment_method(),'mollie_wc_gateway_')) $issues[]='import_gateway';
        if (!$parent) $issues[]='import_source_missing';
        if ($subscription->get_date('trial_end','gmt') || $subscription->get_date('end','gmt')) $issues[]='import_trial_end';
        if (method_exists($subscription,'is_manual') && $subscription->is_manual()) $issues[]='import_manual_renewal';
        $date=$subscription->get_date('next_payment','gmt');
        if ($date) {
            try { $date=(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d'); }
            catch (\Exception $e) { $date=''; }
        }
        return ['source_site'=>home_url('/'),'subscription_id'=>(string)$subscription->get_id(),'customer_name'=>trim($subscription->get_billing_first_name().' '.$subscription->get_billing_last_name()),'customer_email'=>$subscription->get_billing_email(),'mollie_customer_id'=>$meta('_mollie_customer_id'),'mollie_mandate_id'=>$meta('_mollie_mandate_id'),'mollie_payment_id'=>$meta('_mollie_payment_id'),'mode'=>$meta('_mollie_payment_mode'),'currency'=>$subscription->get_currency(),'amount'=>$subscription->get_total(),'interval'=>$frequency,'next_payment_date'=>(string)$date,'status'=>$subscription->get_status(),'parent_order_id'=>(string)$subscription->get_parent_id(),'_issues'=>$issues];
    }
}
