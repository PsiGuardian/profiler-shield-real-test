<?php
declare(strict_types=1);
/**
 * CI-only Composer manifest preparation.
 * Both variants use exactly the same fixture and Symfony constraint.
 * Usage: php bin/configure_release.php <baseline|candidate> <5.4|6.4|7.4|8.1>
 */
if ($argc !== 3 || !in_array($argv[1], ['baseline','candidate'], true)
    || !in_array($argv[2], ['5.4','6.4','7.4','8.1'], true)) {
    fwrite(STDERR, "Usage: php bin/configure_release.php baseline|candidate 5.4|6.4|7.4|8.1\n");
    exit(2);
}
[$script,$kind,$symfony] = $argv;
$manifest = dirname(__DIR__).'/composer.json';
$composer = json_decode((string)file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
foreach ($composer['require'] as $package => $constraint) {
    if (str_starts_with($package, 'symfony/')) {
        $composer['require'][$package] = $symfony.'.*';
    }
}
if ($kind === 'baseline') {
    // Strictly the published release, not an unpinned ~1.0.0 range.
    $composer['require']['omegaguardian/profiler-shield'] = '1.0.0';
} else {
    // Copy the real GitHub checkout from the sibling directory.
    // The path repository is never a fictional Safe Native adapter.
    $composer['repositories'] = [[
        'type' => 'path',
        'url' => '../_bundle_dev',
        'options' => [
            'symlink' => false,
            'versions' => ['omegaguardian/profiler-shield' => '1.0.1-dev'],
        ],
        'canonical' => true,
    ]];
    $composer['require']['omegaguardian/profiler-shield'] = '1.0.1-dev';
}
file_put_contents($manifest, json_encode($composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "Configured {$kind} + Symfony {$symfony}\n";
