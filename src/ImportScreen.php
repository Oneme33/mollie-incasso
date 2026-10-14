<?php

namespace Lukraak\MollieIncasso;

final class ImportScreen
{
    public function __construct(private readonly Plugin $plugin) {}

    public function authorize(string $mode): void
    {
        if (!current_user_can('manage_options') || !current_user_can('manage_mollie_incasso')) throw new Failure('access_denied');
        $nonce=$_POST['mi_import_nonce']??'';$postedMode=$_POST['mode']??'';
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !is_string($nonce) || !is_string($postedMode) || $postedMode!==$mode || !wp_verify_nonce(wp_unslash($nonce),'mi_import_'.$mode)) throw new Failure('csrf_failed');
    }

    private function field(string $key, string $default=''): string
    {
        return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) { echo '<p>'.esc_html__('Alleen beheerders met instellingenrechten kunnen importgegevens bekijken.','mollie-incasso').'</p>';return; }
        $mode=$this->plugin->config->mode();$source=new WooCommerceImport();$import=new Import();$results=[];$checked=[];$csv='';$kind='woocommerce';$page=1;
        echo '<section class="mi-import-intro"><h2>'.esc_html__('Bestaande abonnementen voorbereiden','mollie-incasso').'</h2><p>'.esc_html__('Lees WooCommerce-abonnementen of een CSV en controleer de Mollie-koppeling. Deze importcontrole maakt geen incasso’s aan en wijzigt of stopt uw bestaande abonnementen niet.','mollie-incasso').'</p><ol><li>'.esc_html__('Gegevens inlezen','mollie-incasso').'</li><li>'.esc_html__('Koppelingen controleren','mollie-incasso').'</li><li>'.esc_html__('Overname voorbereiden','mollie-incasso').'</li></ol><p>'.esc_html__('Het daadwerkelijk overzetten van lopende abonnementen volgt na controle van uw bronplugin en afspraken. Laat WooCommerce tot die tijd uw bestaande abonnementen beheren.','mollie-incasso').'</p></section>';
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
            try {
                $this->authorize($mode);
                $kind=$this->field('import_source');
                $action=isset($_POST['import_index']) ? 'verify' : $this->field('import_action');
                if (!in_array($action,['analyze','verify'],true) || !in_array($kind,['woocommerce','csv'],true)) throw new Failure('invalid_command');
                $pageText=$this->field('import_page','1');
                if (!preg_match('/^[1-9][0-9]{0,3}$/D',$pageText)) throw new Failure('import_page');
                $page=(int)$pageText;
                if ($kind==='woocommerce') $rows=$source->read($page);
                else {
                    $csv=$this->field('import_csv');
                    if (!empty($_FILES['import_file']['name'])) {
                        $file=$_FILES['import_file'];
                        if (!is_scalar($file['error']??null) || (int)$file['error']!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']) || (int)($file['size']??0)>Import::MAX_BYTES || filesize($file['tmp_name'])>Import::MAX_BYTES) throw new Failure('import_invalid_csv');
                        $csv=(string)file_get_contents($file['tmp_name']);
                    }
                    $rows=$import->csv($csv);
                }
                $results=$import->analyze($rows,$mode);
                foreach ($results as &$entry) {
                    if ($this->plugin->store->find('customers','remote_id',(string)$entry['row']['mollie_customer_id'],$mode)) { $entry['issues'][]='import_existing_customer';$entry['candidate']=false; }
                }
                unset($entry);
                if ($action==='verify') {
                    $indexText=$this->field('import_index');
                    if (!preg_match('/^[0-9]{1,2}$/D',$indexText)) throw new Failure('import_not_candidate');
                    $index=(int)$indexText;
                    if (!isset($results[$index]) || !$results[$index]['candidate']) throw new Failure('import_not_candidate');
                    $this->plugin->config->assertRun($mode);
                    $this->plugin->store->limit('import-check:'.get_current_user_id(),20,60);
                    try { $checked[$index]=$import->verify($results[$index]['row'],$mode,new MollieGateway($this->plugin->config,$mode)); }
                    catch (Failure $e) { $results[$index]['issues'][]=$e->reason; }
                }
            } catch (Failure $e) { echo '<div class="notice notice-error inline"><p>'.esc_html(Plugin::message($e->reason)).'</p></div>'; }
        }
        echo '<form method="post" enctype="multipart/form-data" class="mi-admin-form mi-import-form" action="'.esc_url(admin_url('admin.php?page=mollie-incasso-import')).'">';
        wp_nonce_field('mi_import_'.$mode,'mi_import_nonce');
        echo '<input type="hidden" name="mode" value="'.esc_attr($mode).'"><label>'.esc_html__('Waar staan de gegevens?','mollie-incasso').'<select name="import_source"><option value="woocommerce" '.selected($kind,'woocommerce',false).'>'.esc_html__('WooCommerce op deze website','mollie-incasso').'</option><option value="csv" '.selected($kind,'csv',false).'>'.esc_html__('CSV van een andere website','mollie-incasso').'</option></select></label><p class="mi-help">'.esc_html($source->available()?__('WooCommerce Subscriptions is op deze website beschikbaar.','mollie-incasso'):__('WooCommerce Subscriptions is hier niet actief. U kunt wel een CSV controleren. De plugin blijft zonder WooCommerce werken.','mollie-incasso')).'</p>';
        echo '<label>'.esc_html__('Pagina van WooCommerce-abonnementen (50 per keer)','mollie-incasso').'<input type="number" name="import_page" min="1" max="9999" value="'.esc_attr((string)$page).'"></label><details '.($kind==='csv'?'open':'').'><summary>'.esc_html__('CSV uploaden of plakken','mollie-incasso').'</summary><label>'.esc_html__('CSV-bestand (UTF-8, maximaal 1 MB en 50 regels)','mollie-incasso').'<input type="file" name="import_file" accept=".csv,text/csv"></label><label>'.esc_html__('Of plak de CSV-inhoud','mollie-incasso').'<textarea name="import_csv" rows="7" spellcheck="false">'.esc_textarea(strlen($csv)<=Import::MAX_BYTES?$csv:'').'</textarea></label><p class="mi-help">'.esc_html__('Upload alleen de hieronder vermelde kolommen, zonder API-keys of volledige rekeninggegevens. Gebruik een punt in bedragen; datums zijn JJJJ-MM-DD in Nederlandse tijd. Een klantenexport alleen is onvoldoende.','mollie-incasso').'</p><label>'.esc_html__('Vereiste CSV-kopregel','mollie-incasso').'<textarea readonly rows="3">'.esc_textarea(implode(',',Import::COLUMNS)).'</textarea></label></details><p><button class="button button-primary" name="import_action" value="analyze">'.esc_html__('Gegevens controleren','mollie-incasso').'</button></p>';
        if ($results) {
            echo '<h2>'.esc_html__('Controleoverzicht','mollie-incasso').'</h2><p class="mi-help">'.esc_html__('Geschikt voor voorbereiding betekent dat de basisvelden kloppen. Toestemming, openstaande betalingen, herhaalpogingen en het stoppen van de oude planning moeten nog worden gecontroleerd. Er is nog niets overgezet. Gegevens uit deze controle worden niet blijvend opgeslagen.','mollie-incasso').'</p><div class="mi-import-table" role="region" aria-label="'.esc_attr__('Importcontrole','mollie-incasso').'" tabindex="0"><table class="widefat striped"><thead><tr>';
            foreach (['Klant / bron','Bedrag / frequentie','Volgende betaling','Beoordeling','Mollie'] as $title) echo '<th scope="col">'.esc_html__($title,'mollie-incasso').'</th>';
            echo '</tr></thead><tbody>';
            foreach ($results as $index=>$entry) {
                $row=$entry['row'];
                echo '<tr><td><strong>'.esc_html($row['customer_name']).'</strong><br>'.esc_html($row['customer_email']).'<br><small>'.esc_html($row['source_site'].' #'.$row['subscription_id']).'</small></td><td>'.esc_html($row['currency'].' '.$row['amount']).'<br>'.esc_html($row['interval']).'</td><td>'.esc_html($row['next_payment_date']).'</td><td>';
                if (!$entry['issues']) echo esc_html__('Geschikt voor voorbereiding','mollie-incasso');
                foreach ($entry['issues'] as $issue) echo '<p>'.esc_html(Plugin::message($issue)).'</p>';
                echo '</td><td>';
                if (isset($checked[$index])) echo esc_html__('Betaalkoppeling en machtiging gecontroleerd; toestemming en oude planning nog controleren.','mollie-incasso').'<br>'.esc_html($checked[$index]['masked']);
                elseif ($entry['candidate']) echo '<button class="button" name="import_index" value="'.esc_attr((string)$index).'" type="submit">'.esc_html__('Mollie-koppeling controleren','mollie-incasso').'</button>';
                else echo esc_html__('Eerst gegevens aanvullen of beoordelen.','mollie-incasso');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } elseif (($_SERVER['REQUEST_METHOD']??'')==='POST') echo '<p>'.esc_html__('Geen abonnementen gevonden in deze selectie.','mollie-incasso').'</p>';
        echo '</form>';
    }
}
