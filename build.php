
<?php

/**
 * Build script for creating PHAR files from all EconomyS plugins
 */

// Create build directory if it doesn't exist
if (!is_dir('build')) {
    mkdir('build', 0755, true);
}

// Define plugins to build
$plugins = [
    'EconomyAPI',
    'EconomyShop', 
    'EconomyProperty',
    'EconomyLand',
    'EconomyUsury',
    'EconomyCasino',
    'EconomySell',
    'EconomyAuction',
    'EconomyAirport',
    'EconomyPShop',
    'EconomyJob',
    'EconomyTax'
];

foreach ($plugins as $plugin) {
    $pluginDir = $plugin;
    
    if (!is_dir($pluginDir)) {
        echo "Warning: Plugin directory $pluginDir not found, skipping...\n";
        continue;
    }
    
    $pharFile = "build/$plugin.phar";
    
    // Remove existing PHAR if exists
    if (file_exists($pharFile)) {
        unlink($pharFile);
    }
    
    try {
        $phar = new Phar($pharFile);
        $phar->startBuffering();
        
        // Add plugin.yml
        if (file_exists("$pluginDir/plugin.yml")) {
            $phar->addFile("$pluginDir/plugin.yml", "plugin.yml");
        }
        
        // Add source files
        if (is_dir("$pluginDir/src")) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator("$pluginDir/src", RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace("$pluginDir/", '', $file->getPathname());
                    $phar->addFile($file->getPathname(), $relativePath);
                }
            }
        }
        
        // Add resources
        if (is_dir("$pluginDir/resources")) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator("$pluginDir/resources", RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = str_replace("$pluginDir/", '', $file->getPathname());
                    $phar->addFile($file->getPathname(), $relativePath);
                }
            }
        }
        
        $phar->stopBuffering();
        echo "✓ Built $plugin.phar successfully\n";
        
    } catch (Exception $e) {
        echo "✗ Failed to build $plugin.phar: " . $e->getMessage() . "\n";
    }
}

echo "\nBuild complete! Check the build/ directory for PHAR files.\n";
?>
