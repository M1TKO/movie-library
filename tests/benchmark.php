<?php

declare(strict_types=1);
// Unique synthetic data directory; never uses inherited owner configuration or media.
$root = dirname(__DIR__);
$data = $root . '/var/benchmark-' . bin2hex(random_bytes(5));
$_SERVER['APP_DATA_DIR'] = $_ENV['APP_DATA_DIR'] = $data;
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_DATA_DIR=' . $data);
putenv('APP_ENV=test');
require $root . '/config/bootstrap.php';
$kernel = new App\Kernel('test', false);
$app = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$app->setAutoExit(false);
if ($app->run(new Symfony\Component\Console\Input\ArrayInput(['command' => 'doctrine:migrations:migrate','--no-interaction' => true]), new Symfony\Component\Console\Output\NullOutput()) !== 0) {
    throw new RuntimeException('Migration failed.');
}
$db = $kernel->getContainer()->get('test.service_container')->get(Doctrine\DBAL\Connection::class);
$now = gmdate(DATE_ATOM);
$hash = password_hash('benchmark-password', PASSWORD_DEFAULT);
$db->insert('owner_auth', ['id' => 1,'password_hash' => $hash,'created_at' => $now]);
$db->transactional(function () use ($db, $now): void {
    $db->insert('locations', ['label' => 'Synthetic fixture','root_path' => '/synthetic','normalized_root' => '/synthetic','created_at' => $now]);
    $location = (int) $db->lastInsertId();
    for ($i = 1;$i <= 1000;++$i) {
        $db->insert('series', ['id' => $i,'title' => sprintf('Fixture Show %04d', $i),'created_at' => $now,'updated_at' => $now]);
        $db->insert('seasons', ['id' => $i,'series_id' => $i,'season_number' => 1]);
    }
    for ($i = 1;$i <= 10000;++$i) {
        $db->insert('media_files', ['id' => $i,'filename' => sprintf('Fixture.%05d.1080p.mkv', $i),'original_path' => sprintf('/synthetic/Fixture.%05d.mkv', $i),'normalized_path' => sprintf('/synthetic/Fixture.%05d.mkv', $i),'size_bytes' => 1000000000 + $i,'height' => 1080,'last_known_availability' => 'available','check_outcome' => $i % 10 === 0 ? 'missing' : 'available','created_at' => $now,'updated_at' => $now]);
        $db->insert('file_locations', ['file_id' => $i,'location_id' => $location,'relative_path' => sprintf('Fixture.%05d.mkv', $i)]);
        if ($i <= 5000) {
            $db->insert('movies', ['id' => $i,'title' => sprintf('Fixture Movie %05d', $i),'watched' => $i % 2,'release_year' => 2020,'created_at' => $now,'updated_at' => $now]);
            $db->insert('file_content_links', ['file_id' => $i,'movie_id' => $i]);
        } else {
            $episode = $i - 5000;
            $season = (int) ceil($episode / 5);
            $db->insert('episodes', ['id' => $episode,'season_id' => $season,'episode_number' => ($episode - 1) % 5 + 1,'title' => 'Fixture episode','watched' => $i % 2,'created_at' => $now,'updated_at' => $now]);
            $db->insert('file_content_links', ['file_id' => $i,'episode_id' => $episode]);
        }
    }
});
$kernel->shutdown();
$kernel->boot();
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel);
$client->loginUser(new App\Security\Owner($hash));
$measure = static function (string $url) use ($client): float {
    $start = hrtime(true);
    $client->request('GET', $url);
    $time = (hrtime(true) - $start) / 1e6;
    if ($client->getResponse()->getStatusCode() !== 200) {
        throw new RuntimeException('Benchmark response failed: ' . $url);
    }
    fwrite(STDERR, $url . " " . round($time, 2) . " ms" . PHP_EOL);
    return round($time, 2);
};
$report = ['platform' => PHP_OS_FAMILY,'php' => PHP_VERSION,'sqlite' => $db->fetchOne('SELECT sqlite_version()'),'files' => 10000,'movies' => 5000,'series' => 1000,'episodes' => 5000,'data' => $data,'first_page_ms' => $measure('/')];
$report['warm_responses_ms'] = [];
foreach (['/?view=movies','/?view=series','/?q=Fixture&availability=available&resolution=1080','/?view=movies&q=Movie&watched=watched','/?view=series&watched=partial','/catalog/movies/1','/catalog/series/1','/seasons/1'] as $url) {
    $measure($url);
    $times = [];
    for ($i = 0;$i < 3;++$i) {
        $times[] = $measure($url);
    }
    $report['warm_responses_ms'][$url] = max($times);
}
$db->executeStatement('DELETE FROM messenger_messages');
$db->executeStatement('DELETE FROM run_locations');
$db->executeStatement('DELETE FROM runs');
$checks = $kernel->getContainer()->get('test.service_container')->get(App\Availability\CheckDispatcher::class);
$start = hrtime(true);
$checks->dispatch('manual');
$report['check_dispatch_ms'] = round((hrtime(true) - $start) / 1e6, 2);
$scans = $kernel->getContainer()->get('test.service_container')->get(App\Discovery\ScanDispatcher::class);
$start = hrtime(true);
$scans->dispatch();
$report['scan_dispatch_ms'] = round((hrtime(true) - $start) / 1e6, 2);
$report['peak_memory_mib'] = round(memory_get_peak_usage(true) / 1048576, 1);
$report['passes_targets'] = $report['first_page_ms'] < 2000 && max($report['warm_responses_ms']) < 1000 && max($report['scan_dispatch_ms'], $report['check_dispatch_ms']) < 1000;
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents($data . '/results.json',$json);
echo $json . PHP_EOL;
exit($report['passes_targets'] ? 0 : 1);
