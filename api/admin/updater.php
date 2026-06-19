<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

$RELEASE_SERVER = 'https://beauty.servicehelp.com.ua/releases';
$PROTECTED = ['api/config/database.php', 'api/config/license.json', '.htaccess'];

$action = $_GET['act'] ?? '';

// === CHECK ===
if ($action === 'check') {
    $ch = curl_init($RELEASE_SERVER . '/manifest.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$result) {
        jsonError('Cannot reach update server');
        exit;
    }
    
    $manifest = json_decode($result, true);
    
    // Поточна версія
    $pdo = getDb();
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'app_version'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current = $row ? $row['setting_value'] : '1.0.0';
    
    jsonOk([
        'current' => $current,
        'latest' => $manifest['latest_version'] ?? '1.0.0',
        'needs_update' => version_compare($current, $manifest['latest_version'] ?? '1.0.0', '<'),
        'changelog' => $manifest['changelog'] ?? '',
        'date' => $manifest['release_date'] ?? '',
    ]);
    exit;
}

// === UPDATE ===
if ($action === 'run') {
    set_time_limit(300);
    $log = [];
    
    try {
        // 1. Manifest
        $ch = curl_init($RELEASE_SERVER . '/manifest.json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $manifest = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        $newVer = $manifest['latest_version'] ?? '';
        if (!$newVer) throw new Exception('No version in manifest');
        
        $log[] = ['s' => "Found v{$newVer}", 'ok' => true];
        
        // 2. Download ZIP
        $zipUrl = $RELEASE_SERVER . '/' . $newVer . '.zip';
        $tmpZip = sys_get_temp_dir() . '/salon_' . $newVer . '.zip';
        
        $ch = curl_init($zipUrl);
        $fp = fopen($tmpZip, 'w');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if ($httpCode !== 200 || filesize($tmpZip) < 100) {
            throw new Exception('Download failed (HTTP ' . $httpCode . ')');
        }
        
        $log[] = ['s' => 'Downloaded ' . round(filesize($tmpZip) / 1024) . ' KB', 'ok' => true];
        
        // 3. Backup
        $root = realpath(__DIR__ . '/../../');
        $backDir = $root . '/backups/' . date('Y-m-d_His');
        @mkdir($backDir, 0755, true);
        foreach (['index.html','index.php','manage.html','master.html'] as $f) {
            if (file_exists($root . '/' . $f)) @copy($root . '/' . $f, $backDir . '/' . $f);
        }
        $log[] = ['s' => 'Backup created', 'ok' => true];
        
        // 4. Extract
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) throw new Exception('Cannot open ZIP');
        
        $extracted = 0;
        $skipped = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -1) === '/') continue;
            
            $skip = false;
            foreach ($PROTECTED as $pf) { if ($name === $pf) { $skip = true; break; } }
            if (strpos($name, 'uploads/') === 0) $skip = true;
            
            if ($skip) { $skipped++; continue; }
            
            $target = $root . '/' . $name;
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            
            $data = $zip->getFromIndex($i);
            if ($data !== false) { file_put_contents($target, $data); $extracted++; }
        }
        $zip->close();
        @unlink($tmpZip);
        
        $log[] = ['s' => "{$extracted} files updated, {$skipped} protected", 'ok' => true];
        
        // 5. DB migrations
        $pdo = getDb();
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'app_version'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $curVer = $row ? $row['setting_value'] : '1.0.0';
        
        $mig = 0;
        $mErr = 0;
        foreach (($manifest['migrations'] ?? []) as $ver => $queries) {
            if (version_compare($ver, $curVer, '<=')) continue;
            foreach ($queries as $sql) {
                try { $pdo->exec($sql); $mig++; } 
                catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                        $mErr++;
                    }
                }
            }
        }
        $log[] = ['s' => "DB: {$mig} migrations" . ($mErr ? ", {$mErr} errors" : ''), 'ok' => $mErr === 0];
        
        // 6. Version
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('app_version', '{$newVer}') ON DUPLICATE KEY UPDATE setting_value = '{$newVer}'");
        
        if (function_exists('opcache_reset')) opcache_reset();
        
        $log[] = ['s' => "Updated to v{$newVer} ✓", 'ok' => true];
        
        jsonOk(['log' => $log, 'version' => $newVer]);
        
    } catch (Throwable $e) {
        $log[] = ['s' => $e->getMessage(), 'ok' => false];
        jsonOk(['log' => $log, 'error' => $e->getMessage()]);
    }
    exit;
}

jsonError('Unknown action');