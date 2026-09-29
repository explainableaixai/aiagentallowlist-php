<?php

/**
 * Command-line lookup tool.
 *
 *   php examples/cli.php check <url> [METHOD]     verdict for one URL, exit code 0 allow, 1 deny, 3 flag
 *   php examples/cli.php types <domain>           verified page-type map
 *   php examples/cli.php denylist <domain>        write-surface URLs an agent should not open
 *   php examples/cli.php json <url> [METHOD]      raw API record
 *
 * Reads the key from AIAGENTALLOWLIST_API_KEY.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\AuthenticationException;

$cmd = $argv[1] ?? '';
$target = $argv[2] ?? '';
$method = $argv[3] ?? 'GET';

if ($cmd === '' || $target === '') {
    fwrite(STDERR, "usage: php cli.php check|types|denylist|json <url-or-domain> [METHOD]\n");
    exit(2);
}

$client = new Client(aal_api_key());

try {
    switch ($cmd) {
        case 'check':
            $v = $client->check($target, $method);
            printf("%-6s %s %s\n", strtoupper($v->verdict() ?? '?'), strtoupper($method), $target);
            printf("layer=%s id=%s scope=%s\n", $v->matchedLayer() ?? '-', $v->matchedId() ?? '-', $v->verdictScope() ?? '-');
            if ($v->matchedNote() !== null) {
                echo 'note: ', $v->matchedNote(), PHP_EOL;
            }
            exit($v->allowed() ? 0 : ($v->flagged() ? 3 : 1));
        case 'types':
            foreach ($client->pageTypes($target) as $type => $url) {
                printf("%-16s %s\n", $type, $url);
            }
            exit(0);
        case 'denylist':
            foreach ($client->denyList($target) as $url) {
                echo $url, PHP_EOL;
            }
            exit(0);
        case 'json':
            echo json_encode($client->check($target, $method), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
            exit(0);
        default:
            fwrite(STDERR, "unknown command: {$cmd}\n");
            exit(2);
    }
} catch (AuthenticationException $e) {
    fwrite(STDERR, "API key rejected: {$e->getMessage()}\n");
    exit(4);
} catch (AIAgentAllowlistException $e) {
    fwrite(STDERR, "Lookup failed (HTTP {$e->getStatus()}): {$e->getMessage()}\n");
    exit(5);
}
