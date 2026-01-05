<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_helpers.php';

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL: {$message}. Expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

$existing = [
    'hex' => 'ABC123',
    'lat' => 32.1234,
    'lon' => -116.1234,
    'alt' => 10000,
    'seen_pos' => 5,
];

$incomingSame = [
    'hex' => 'ABC123',
    'lat' => 32.12345,
    'lon' => -116.12346,
    'alt' => 10050,
    'seen_pos' => 6,
];

assertTrue(
    !shouldReplaceEntry($existing, $incomingSame, 100, 3),
    'Should not replace when seen_pos is not newer and no significant geo change.'
);

$incomingNewerNoChange = [
    'hex' => 'ABC123',
    'lat' => 32.12346,
    'lon' => -116.12345,
    'alt' => 10020,
    'seen_pos' => 2,
];

assertTrue(
    !shouldReplaceEntry($existing, $incomingNewerNoChange, 100, 3),
    'Should not replace when seen_pos is newer but geo change is not significant.'
);

$incomingNewerWithChange = [
    'hex' => 'ABC123',
    'lat' => 32.5678,
    'lon' => -116.1234,
    'alt' => 10250,
    'seen_pos' => 2,
];

assertTrue(
    shouldReplaceEntry($existing, $incomingNewerWithChange, 100, 3),
    'Should replace when seen_pos is newer and geo change is significant.'
);

$entries = [
    $existing,
    $incomingNewerWithChange,
];

$deduped = dedupeEntries($entries, 100, 3);
assertSame(1, count($deduped), 'Deduped list should contain one entry.');
assertSame(32.5678, $deduped[0]['lat'], 'Deduped entry should be the newer significant update.');

$cleanupMap = [
    'AAA111' => ['seen_pos' => 100],
    'BBB222' => ['seen_pos' => 400],
    'CCC333' => ['seen_pos' => null],
];
$removed = cleanupStaleEntries($cleanupMap, 300);
assertSame(2, $removed, 'Cleanup should remove two stale entries.');
assertSame(1, count($cleanupMap), 'Cleanup should leave one entry.');

$logMessages = [];
$logger = static function (string $message) use (&$logMessages): void {
    $logMessages[] = $message;
};

logFilterDiscard($logger, $existing, 'fir_outside');
assertTrue(count($logMessages) === 1, 'Logger should capture one message.');
assertTrue(strpos($logMessages[0], 'reason=fir_outside') !== false, 'Log message should include reason.');

fwrite(STDOUT, "OK\n");
