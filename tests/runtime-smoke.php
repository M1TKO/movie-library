<?php
declare(strict_types=1);
// Separate synthetic data; never reads the owner's catalog.
require dirname(__DIR__).'/vendor/autoload.php';
use Symfony\Component\Process\Process;

$root=dirname(__DIR__);
$data=$root.'/var/runtime-smoke-'.bin2hex(random_bytes(6));
mkdir($data,0700,true);
$env=['APP_ENV'=>'prod','APP_DEBUG'=>'0','APP_DATA_DIR'=>$data];
$caddy=$argv[1] ?? 'caddy';
$cgi=$argv[2] ?? 'php-cgi';
$port=$argv[3] ?? '8083';
$fcgi=$argv[4] ?? '9083';
$command=[PHP_BINARY,'bin/console','app:serve','--port='.$port,'--fcgi-port='.$fcgi,'--caddy='.$caddy,'--php-cgi='.$cgi];
$server=null;
$checks=0;
$assert=static function(bool $value,string $message) use (&$checks): void { if (!$value) { throw new RuntimeException($message); } ++$checks; };
$start=static function() use ($command,$root,$env,&$server): void {
    $server=new Process($command,$root,$env,null,null);
    $server->start();
    $deadline=microtime(true)+40;
    while (microtime(true)<$deadline) {
        if (str_contains($server->getOutput(),'Movie Library ready:')) { return; }
        if (!$server->isRunning()) { throw new RuntimeException($server->getErrorOutput().$server->getOutput()); }
        usleep(200000);
    }
    throw new RuntimeException('Supervisor did not become ready: '.$server->getErrorOutput());
};
$stop=static function() use ($root,$env,&$server): void {
    (new Process([PHP_BINARY,'bin/console','app:stop'],$root,$env,null,25))->mustRun();
    $server?->wait();
};
try {
    $start();
    $health=json_decode(file_get_contents('http://127.0.0.1:'.$port.'/health'),true);
    $assert($health['status']==='ok','HTTP health failed');
    $assert($health['php']===PHP_VERSION,'Web/CLI version mismatch');
    $assert(str_contains(file_get_contents('http://127.0.0.1:'.$port.'/setup'),'Create owner account'),'Setup HTML missing');
    $assert(str_contains(file_get_contents('http://127.0.0.1:'.$port.'/app.css'),':root'),'Stylesheet not served');
    $assert(str_contains(file_get_contents('http://127.0.0.1:'.$port.'/tmdb-logo.svg'),'<svg'),'TMDB attribution logo not served');
    $context=stream_context_create(['http'=>['ignore_errors'=>true]]);
    $private=file_get_contents('http://127.0.0.1:'.$port.'/.env',false,$context);
    $assert(str_contains($http_response_header[0],'404'),'Private config request not rejected');
    $duplicate=new Process($command,$root,$env,null,10); $duplicate->run();
    $assert(!$duplicate->isSuccessful() && str_contains($duplicate->getErrorOutput(),'already running'),'Duplicate supervisor accepted');
    $duplicateWorker=new Process([PHP_BINARY,'bin/console','messenger:consume','filesystem','--time-limit=2'],$root,$env,null,10); $duplicateWorker->run();
    $assert(!$duplicateWorker->isSuccessful() && str_contains($duplicateWorker->getErrorOutput(),'already owns'),'Duplicate worker accepted');
    $db=new PDO('sqlite:'.$data.'/library.sqlite');
    $assert((int)$db->query('SELECT COUNT(*) FROM worker_receipts')->fetchColumn()===2,'Both queues must acknowledge startup');
    $db->exec("INSERT INTO locations(label,root_path,normalized_root,created_at) VALUES('Persistence fixture','/synthetic','/synthetic','2026-10-11T00:00:00Z')");
    $db->exec("INSERT INTO media_files(original_path,normalized_path,filename,last_known_availability,created_at,updated_at) VALUES('/synthetic/known.mkv','/synthetic/known.mkv','known.mkv','available','2026-10-11T00:00:00Z','2026-10-11T00:00:00Z')");
    $db->exec('INSERT INTO file_locations(file_id,location_id,relative_path) SELECT f.id,l.id,\'known.mkv\' FROM media_files f CROSS JOIN locations l');
    $stop();
    $assert($server->isSuccessful(),'Supervisor did not exit cleanly');
    clearstatcache(true,$data.'/runtime.json');
    $assert(!is_file($data.'/runtime.json'),'Runtime ownership file was not removed');
    $assert((int)$db->query("SELECT COUNT(*) FROM worker_health WHERE state='stopped'")->fetchColumn()===2,'Workers not recorded as stopped');
    $start();
    $assert($db->query('SELECT label FROM locations')->fetchColumn()==='Persistence fixture','Migration/restart lost saved data');
    $deadline = microtime(true)+10;
    while (microtime(true)<$deadline && !(int)$db->query("SELECT COUNT(*) FROM runs WHERE job_type='availability' AND state IN ('completed','completed_with_errors')")->fetchColumn()) { usleep(100000); }
    $assert((int)$db->query("SELECT COUNT(*) FROM runs WHERE job_type='availability' AND trigger_name='backend_start'")->fetchColumn()===1,'Restart must enqueue exactly one availability check');
    $assert($db->query('SELECT last_known_availability FROM media_files')->fetchColumn()==='available','Offline startup must retain last-known availability');
    file_get_contents('http://127.0.0.1:'.$port.'/health');
    $assert((int)$db->query("SELECT COUNT(*) FROM runs WHERE job_type='availability'")->fetchColumn()===1,'HTTP requests must not repeat backend-start checks');
    $state=json_decode(file_get_contents($data.'/runtime.json'),true);
    $workerPid=$state['children']['metadata'];
    // Kill only the synthetic supervisor's recorded child to verify failure propagation.
    if (PHP_OS_FAMILY==='Windows') {
        (new Process(['taskkill','/F','/T','/PID',(string)$workerPid],null,null,null,10))->mustRun();
    } else {
        $assert(posix_kill($workerPid,SIGTERM),'Could not terminate test worker');
    }
    $deadline=microtime(true)+20;
    while ($server->isRunning() && microtime(true)<$deadline) { usleep(200000); }
    $assert(!$server->isRunning() && !$server->isSuccessful(),'Worker loss did not fail the supervisor');
    $assert(str_contains($server->getErrorOutput(),'metadata stopped'),'Worker failure lacks an actionable error');
    clearstatcache(true,$data.'/runtime.json');
    $assert(!is_file($data.'/runtime.json'),'Failed supervisor left runtime ownership behind');
    echo "Runtime smoke passed: $checks checks; ".PHP_OS_FAMILY.'; PHP '.PHP_VERSION.'; SQLite '.$db->query('select sqlite_version()')->fetchColumn()."\n";
} finally {
    if ($server?->isRunning()) { try { $stop(); } catch (Throwable) { $server->stop(3); } }
}
