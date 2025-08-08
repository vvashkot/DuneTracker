<?php
/**
 * FTP Deployment Script
 * Run this locally to deploy changes via FTP
 * Usage: php ftp-deploy.php
 */

// Configuration (env vars override defaults)
$config = [
    'ftp_host' => getenv('FTP_HOST') ?: '147.93.42.38', // no protocol
    'ftp_user' => getenv('FTP_USER') ?: 'u442226222',
    'ftp_pass' => getenv('FTP_PASS') ?: '', // leave empty to be prompted
    'ftp_path' => getenv('FTP_PATH') ?: '/domains/houserubi-ka.com/public_html/', // must end with '/'
    'local_path' => __DIR__ . '/../public_html/',
    'exclude' => [
        'config.local.php',
        '.DS_Store',
        '.git',
        'deploy',
        '*.log'
    ]
];

class FTPDeployer {
    private $conn;
    private $config;
    private $files_uploaded = 0;
    private $files_skipped = 0;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    public function deploy() {
        echo "🚀 Starting FTP deployment...\n";
        
        // Connect to FTP
        $this->connect();
        
        // Get list of files to upload
        $files = $this->getLocalFiles($this->config['local_path']);
        
        echo "📁 Found " . count($files) . " files to check\n";
        
        // Upload each file
        foreach ($files as $file) {
            $this->uploadFile($file);
        }
        
        // Close connection
        ftp_close($this->conn);
        
        echo "\n✅ Deployment complete!\n";
        echo "📊 Files uploaded: {$this->files_uploaded}\n";
        echo "📊 Files skipped: {$this->files_skipped}\n";
    }
    
    private function connect() {
        $this->conn = ftp_connect($this->config['ftp_host']);
        if (!$this->conn) {
            die("❌ Could not connect to FTP server\n");
        }
        
        if (!ftp_login($this->conn, $this->config['ftp_user'], $this->config['ftp_pass'])) {
            die("❌ Could not login to FTP server\n");
        }
        
        ftp_pasv($this->conn, true);
        echo "✅ Connected to FTP server\n";
    }
    
    private function getLocalFiles($dir, $base = '') {
        $files = [];
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $path = $dir . '/' . $item;
            $relative = $base ? $base . '/' . $item : $item;
            
            // Check if excluded
            if ($this->isExcluded($relative)) {
                continue;
            }
            
            if (is_dir($path)) {
                $files = array_merge($files, $this->getLocalFiles($path, $relative));
            } else {
                $files[] = $relative;
            }
        }
        
        return $files;
    }
    
    private function isExcluded($path) {
        foreach ($this->config['exclude'] as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
    
    private function uploadFile($file) {
        $local_file = $this->config['local_path'] . $file;
        $remote_file = $this->config['ftp_path'] . $file;
        
        // Check if remote file exists and compare modification times
        $local_time = filemtime($local_file);
        $remote_time = ftp_mdtm($this->conn, $remote_file);
        
        if ($remote_time != -1 && $remote_time >= $local_time) {
            $this->files_skipped++;
            return;
        }
        
        // Create directory if needed
        $remote_dir = dirname($remote_file);
        $this->createRemoteDir($remote_dir);
        
        // Upload file
        if (ftp_put($this->conn, $remote_file, $local_file, FTP_BINARY)) {
            echo "📤 Uploaded: $file\n";
            $this->files_uploaded++;
        } else {
            echo "❌ Failed: $file\n";
        }
    }
    
    private function createRemoteDir($dir) {
        $parts = explode('/', $dir);
        $path = '';
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            $path .= '/' . $part;
            
            // Check if directory exists
            if (!@ftp_chdir($this->conn, $path)) {
                ftp_mkdir($this->conn, $path);
            }
        }
        
        // Return to root
        ftp_chdir($this->conn, '/');
    }
}

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

// Get password from environment or prompt
if (!isset($config['ftp_pass']) || empty($config['ftp_pass'])) {
    echo "FTP Password: ";
    $config['ftp_pass'] = trim(fgets(STDIN));
}

// Run deployment
$deployer = new FTPDeployer($config);
$deployer->deploy();