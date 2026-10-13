<?php
declare(strict_types=1);
namespace App\Maintenance;
use Doctrine\DBAL\Connection;
final class Preferences
{
    public function __construct(private readonly Connection $db) {}
    public function values(): array
    {
        $defaults=['page_size'=>'50','probe_concurrency'=>'2','poster_cache_mib'=>'100','log_level'=>'errors','retention_days'=>'30','lan_address'=>'','lan_enabled'=>'false'];
        foreach ($this->db->fetchAllAssociative('SELECT name,value FROM settings') as $row) { $defaults[$row['name']]=$row['value']; }
        return $defaults;
    }
    public function validate(array $input): array
    {
        foreach (['page_size'=>[25,50,100],'probe_concurrency'=>[1,2],'log_level'=>['errors','verbose']] as $name=>$allowed) {
            if (!in_array($input[$name]??null,array_map('strval',$allowed),true)) { throw new \InvalidArgumentException('Invalid preference: '.$name); }
        }
        foreach (['poster_cache_mib'=>[10,1024],'retention_days'=>[1,365]] as $name=>[$min,$max]) {
            if (!ctype_digit($input[$name]??'') || (int)$input[$name]<$min || (int)$input[$name]>$max) { throw new \InvalidArgumentException($name.' must be between '.$min.' and '.$max.'.'); }
        }
        $input['lan_address']=trim($input['lan_address']??'');
        if ($input['lan_address']!=='' && !\App\Security\NetworkPolicy::privateAddress($input['lan_address'])) { throw new \InvalidArgumentException('LAN address must be a private IPv4 address assigned to this host.'); }
        if (($input['lan_enabled']??'false')==='true' && $input['lan_address']==='') { throw new \InvalidArgumentException('Enter the private address before enabling LAN.'); }
        return array_intersect_key($input,array_flip(['page_size','probe_concurrency','poster_cache_mib','log_level','retention_days','lan_address','lan_enabled']));
    }
}
