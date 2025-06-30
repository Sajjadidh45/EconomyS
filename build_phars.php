<?php

// List of all Economy plugins to build
$plugins = [
    'EconomyAPI',
    'EconomyShop', 
    'EconomyLand',
    'EconomyProperty',
    'EconomyPShop',
    'EconomySell',
    'EconomyAirport',
    'EconomyAuction',
    'EconomyCasino',
    'EconomyJob',
    'EconomyTax',
    'EconomyUsury'
];

// Create build directory if it doesn't exist
if (!file_exists('build')) {
    mkdir('build', 0755, true);
}

echo "Building PHAR files for Economy plugins...\n";

foreach ($plugins as $plugin) {
    $pluginDir = __DIR__ . '/' . $plugin;
    $pharFile = __DIR__ . '/build/' . $plugin . '.phar';
    
    if (!file_exists($pluginDir)) {
        echo "Warning: Plugin directory $plugin not found, skipping...\n";
        continue;
    }
    
    // Remove existing PHAR file if it exists
    if (file_exists($pharFile)) {
        unlink($pharFile);
    }
    
    try {
        // Create PHAR archive
        $phar = new Phar($pharFile);
        $phar->setSignatureAlgorithm(Phar::SHA1);
        $phar->startBuffering();
        
        // Add all files from plugin directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !in_array($file->getFilename(), ['.', '..'])) {
                $relativePath = str_replace($pluginDir . '/', '', $file->getPathname());
                $phar->addFile($file->getPathname(), $relativePath);
            }
        }
        
        // Set the stub (entry point)
        $phar->setStub("<?php __HALT_COMPILER();");
        $phar->stopBuffering();
        
        echo "✓ Built $plugin.phar successfully\n";
        
    } catch (Exception $e) {
        echo "✗ Failed to build $plugin.phar: " . $e->getMessage() . "\n";
    }
}

echo "\nBuild process completed! PHAR files are in the 'build' directory.\n";
