<?php

namespace Lukraak\MollieIncasso;

/** Read-only analysis. A candidate is never permission to debit. */
final class Import
{
    public const LIMIT = 50;
    public const MAX_BYTES = 1048576;
    public const COLUMNS = ['source_site','subscription_id','customer_name','customer_email','mollie_customer_id','mollie_mandate_id','mollie_payment_id','mode','currency','amount','interval','next_payment_date','status','parent_order_id'];

    public function csv(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES || str_contains($csv, "\0") || !mb_check_encoding($csv, 'UTF-8')) {
            throw new Failure('import_invalid_csv');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv); rewind($stream);
        try {
            $first = strtok($csv, "\r\n") ?: '';
            $separator = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = fgetcsv($stream, 0, $separator, '"', '');
            if (!$header || count($header) > 40) throw new Failure('import_invalid_csv');
            $header = array_map(static fn($v) => strtolower(trim((string) $v)), $header);
            $aliases=['subscription_status'=>'status','customer_email'=>'customer_email','_mollie_customer_id'=>'mollie_customer_id','_mollie_mandate_id'=>'mollie_mandate_id','_mollie_payment_id'=>'mollie_payment_id','_mollie_payment_mode'=>'mode','next_payment_date'=>'next_payment_date'];
            $header = array_map(static fn($v) => $aliases[$v] ?? $v, $header);
            if (count(array_unique($header)) !== count($header) || array_diff(self::COLUMNS, $header)) throw new Failure('import_csv_columns');
            // Unknown columns can contain bank details or secrets. Refuse rather than retain them.
            if (array_diff($header, self::COLUMNS)) throw new Failure('import_csv_columns');
            $rows=[];
            while (($values=fgetcsv($stream, 0, $separator, '"', '')) !== false) {
                if ($values === [null] || $values === ['']) continue;
                if (count($rows) >= self::LIMIT) throw new Failure('import_limit');
                if (count($values)!==count($header)) throw new Failure('import_invalid_csv');
                $row=array_combine($header, array_map('trim', $values));
                foreach ($row as $value) if (strlen($value)>1000) throw new Failure('import_invalid_csv');
                $rows[]=$row;
            }
            if (!$rows) throw new Failure('import_invalid_csv');
            return $rows;
        } finally { fclose($stream); }
    }

    public function serialize(array $rows): string
    {
        $stream=fopen('php://temp','r+');
        fputcsv($stream,self::COLUMNS,',','"','');
        foreach ($rows as $row) fputcsv($stream,array_map(static fn($key)=>(string)($row[$key]??''),self::COLUMNS),',','"','');
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }

    public function analyze(array $rows, string $mode): array
    {
        if (!in_array($mode,['test','live'],true)) throw new Failure('invalid_mode');
        if (count($rows)>self::LIMIT) throw new Failure('import_limit');
        $sources=[];$customers=[];
        foreach ($rows as $row) {
            $key=($row['source_site']??'').'|'.($row['subscription_id']??'');$sources[$key]=($sources[$key]??0)+1;
            $key=($row['mode']??'').'|'.($row['mollie_customer_id']??'');$customers[$key]=($customers[$key]??0)+1;
        }
        $result=[];
        foreach ($rows as $row) {
            $issues=[];
            $site=wp_parse_url((string)($row['source_site']??''));
            if (!$site || empty($site['host']) || !in_array($site['scheme']??'',['https','http'],true) || isset($site['user']) || isset($site['pass'])) $issues[]='import_source_missing';
            foreach (['subscription_id','parent_order_id'] as $key) if (!preg_match('/^[1-9][0-9]{0,17}$/D',(string)($row[$key]??''))) $issues[]='import_source_missing';
            if (trim((string)($row['customer_name']??''))==='' || !is_email($row['customer_email']??'')) $issues[]='import_customer_missing';
            foreach (['mollie_customer_id'=>'cst_','mollie_mandate_id'=>'mdt_','mollie_payment_id'=>'tr_'] as $key=>$prefix) if (!preg_match('/^'.$prefix.'[A-Za-z0-9]{1,140}$/D',(string)($row[$key]??''))) $issues[]='import_references_missing';
            if (($row['mode']??'')!==$mode) $issues[]='environment_mismatch';
            if (($row['currency']??'')!=='EUR') $issues[]='import_currency';
            try { Money::cents((string)($row['amount']??'')); } catch (Failure $e) { $issues[]='invalid_amount'; }
            if (!in_array($row['interval']??'',['1 month','3 months','12 months'],true)) $issues[]='import_interval';
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)($row['next_payment_date']??''),new \DateTimeZone('Europe/Amsterdam'));
            if (!$date || $date->format('Y-m-d')!==($row['next_payment_date']??'') || $date->format('Y-m-d')<=wp_date('Y-m-d',null,new \DateTimeZone('Europe/Amsterdam'))) $issues[]='import_date';
            if (($row['status']??'')!=='active') $issues[]='import_source_inactive';
            if (($sources[($row['source_site']??'').'|'.($row['subscription_id']??'')]??0)>1) $issues[]='import_duplicate';
            if (($customers[($row['mode']??'').'|'.($row['mollie_customer_id']??'')]??0)>1) $issues[]='import_shared_customer';
            foreach ($row['_issues']??[] as $issue) $issues[]=$issue;
            $result[]=['row'=>array_intersect_key($row,array_flip(self::COLUMNS)), 'issues'=>array_values(array_unique($issues)), 'candidate'=>!$issues];
        }
        return $result;
    }

    /** Only GET-style gateway calls. No subscriptions, payments or mandates are created. */
    public function verify(array $row, string $mode, Gateway $api): array
    {
        $analysis=$this->analyze([$row],$mode)[0];
        if (!$analysis['candidate']) throw new Failure('import_not_candidate');
        $p=$api->payment($row['mollie_payment_id']);
        if (($p['id']??'')!==$row['mollie_payment_id'] || ($p['mode']??'')!==$mode || ($p['customerId']??'')!==$row['mollie_customer_id'] || ($p['amount']['currency']??'')!=='EUR' || ($p['status']??'')!=='paid' || !in_array($p['sequenceType']??'',['first','recurring'],true) || !in_array($p['method']??'',['ideal','directdebit'],true)) throw new Failure('import_payment_mismatch');
        Money::cents((string)($p['amount']['value']??''));
        if (Money::cents((string)($p['amountRefunded']['value']??'0.00'),true)>0 || Money::cents((string)($p['amountChargedBack']['value']??'0.00'),true)>0) throw new Failure('import_payment_reversed');
        if ((string)($p['metadata']['order_id']??'')!==(string)$row['parent_order_id']) throw new Failure('import_order_mismatch');
        if (!empty($p['mandateId']) && $p['mandateId']!==$row['mollie_mandate_id']) throw new Failure('mandate_mismatch');
        $m=$api->mandate($row['mollie_customer_id'],$row['mollie_mandate_id']);
        if (($m['id']??'')!==$row['mollie_mandate_id'] || !empty($m['customerId']) && $m['customerId']!==$row['mollie_customer_id'] || ($m['method']??'')!=='directdebit' || ($m['status']??'')!=='valid') throw new Failure('mandate_not_valid');
        return ['checked_at'=>Store::now(), 'masked'=>(string)($m['masked']??'')];
    }
}
