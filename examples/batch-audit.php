<?php

/**
 * Batch job: evaluate a URL list and write a CSV policy report.
 *
 * Input: a text file with one URL per line, optionally "METHOD URL" (for example "POST https://x.com/upload").
 * Output: CSV with url, method, verdict, scope, layer, id, language, remaining_lookups.
 *
 *   AIAGENTALLOWLIST_API_KEY=... php examples/batch-audit.php urls.txt report.csv
 *
 * Suitable for cron: re-run nightly over the URLs your agents visited and diff the reports.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\QuotaException;

[$self, $in, $out] = array_pad($argv, 3, null);
if ($in === null || !is_readable($in)) {
    fwrite(STDERR, "usage: php {$self} urls.txt [report.csv]\n");
    exit(2);
}

$client = new Client(aal_api_key(), ['timeout' => 20]);
$fh = $out !== null ? fopen($out, 'wb') : STDOUT;
fputcsv($fh, ['url', 'method', 'verdict', 'scope', 'layer', 'id', 'language', 'remaining_lookups', 'error']);

$jobs = [];
foreach (file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = preg_split('/\s+/', $line, 2);
    if (count($parts) === 2 && in_array(strtoupper($parts[0]), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $jobs[] = [strtoupper($parts[0]), $parts[1]];
    } else {
        $jobs[] = ['GET', $line];
    }
}

$counts = ['allow' => 0, 'deny' => 0, 'flag' => 0, 'error' => 0];
foreach ($jobs as $i => [$method, $url]) {
    if ($i > 0) {
        usleep(150000); // small pause between lookups to stay under the rate limit
    }
    try {
        $v = $client->check($url, $method);
        $counts[$v->verdict() ?? 'error']++;
        fputcsv($fh, [$url, $method, $v->verdict(), $v->verdictScope(), $v->matchedLayer(),
            $v->matchedId(), $v->language(), $v->remainingLookups(), '']);
    } catch (QuotaException $e) {
        fwrite(STDERR, "Quota exhausted after {$i} lookups: {$e->getMessage()}\n");
        break;
    } catch (AIAgentAllowlistException $e) {
        $counts['error']++;
        fputcsv($fh, [$url, $method, '', '', '', '', '', '', $e->getMessage()]);
    }
}

if ($fh !== STDOUT) {
    fclose($fh);
}
fwrite(STDERR, sprintf("allow=%d deny=%d flag=%d error=%d\n",
    $counts['allow'], $counts['deny'], $counts['flag'], $counts['error']));
