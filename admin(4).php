<?php
// ============================================
// ROBUSTE ADMIN.PHP - ATOMIC OPERATIONS
// ============================================

session_start();

$admin_passwort = "workshop2025"; 

$gruppen_labels = [
    'bildung' => 'Bildung',
    'social' => 'Social',
    'individuell' => 'Individuell',
    'politik' => 'Politik',
    'kreativ' => 'Innovation'
];

// ===== AUTO-BACKUP FUNKTION =====

/**
 * AUTO-BACKUP FUNKTION
 * Erstellt automatisch Backups mit Timestamp
 */
function createAutoBackup($file, $keepLast = 10) {
    if (!file_exists($file)) {
        logError("Backup failed: Source file does not exist: $file");
        return false;
    }
    
    // Backup-Verzeichnis erstellen falls nicht vorhanden
    $backupDir = dirname($file) . '/backups';
    if (!is_dir($backupDir)) {
        $created = @mkdir($backupDir, 0777, true);
        if (!$created) {
            logError("Backup failed: Could not create backup directory: $backupDir");
            return false;
        }
        @chmod($backupDir, 0777);
    }
    
    // Backup mit Timestamp erstellen
    $filename = basename($file, '.json');
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/' . $filename . '_backup_' . $timestamp . '.json';
    
    // Kopie erstellen
    $success = @copy($file, $backupFile);
    
    if (!$success) {
        logError("Backup failed: Could not copy file to: $backupFile");
        return false;
    }
    
    // Alte Backups aufräumen
    cleanupOldBackups($backupDir, $filename, $keepLast);
    
    return $success;
}

/**
 * ALTE BACKUPS AUFRÄUMEN
 */
function cleanupOldBackups($backupDir, $filename, $keepLast) {
    $pattern = $backupDir . '/' . $filename . '_backup_*.json';
    $backups = glob($pattern);
    
    if (count($backups) > $keepLast) {
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $toDelete = array_slice($backups, 0, count($backups) - $keepLast);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}

// ===== ATOMIC FILE-HANDLING FUNKTIONEN =====

/**
 * ATOMIC UPDATE - Für Toggle, Move, Focus
 * Führt Read-Modify-Write atomar durch
 */
function atomicUpdate($file, $updateFunction, $maxRetries = 10, $retryDelay = 100000) {
    $attempts = 0;
    
    while ($attempts < $maxRetries) {
        $attempts++;
        
        $fp = fopen($file, 'c+');
        if (!$fp) {
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to open file for update");
            return false;
        }
        
        // 🔒 EXKLUSIVER LOCK
        if (flock($fp, LOCK_EX)) {
            
            // SCHRITT 1: Lesen
            $filesize = filesize($file);
            if ($filesize > 0) {
                clearstatcache(true, $file);
                $content = fread($fp, $filesize);
                $data = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    logError("JSON decode error in atomicUpdate");
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
            } else {
                $data = [];
            }
            
            // SCHRITT 2: Modifizieren via Callback
            $data = $updateFunction($data);
            
            // SCHRITT 3: Schreiben
            ftruncate($fp, 0);
            rewind($fp);
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                logError("JSON encode error in atomicUpdate");
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            $writeResult = fwrite($fp, $json);
            fflush($fp);
            
            // 🔓 UNLOCK
            flock($fp, LOCK_UN);
            fclose($fp);
            
            // ✅ AUTO-BACKUP ERSTELLEN
            if ($writeResult !== false) {
                createAutoBackup($file);
            }
            
            return ($writeResult !== false);
            
        } else {
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to acquire lock for atomic update");
            return false;
        }
    }
    
    return false;
}

/**
 * Safe Read für Display (keine Modification)
 */
function safeReadJson($file, $maxRetries = 3, $retryDelay = 50000) {
    $attempts = 0;
    while ($attempts < $maxRetries) {
        $attempts++;
        
        if (!file_exists($file)) {
            return [];
        }
        
        $fp = fopen($file, 'r');
        if (!$fp) {
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            return [];
        }
        
        if (flock($fp, LOCK_SH)) {
            $filesize = filesize($file);
            if ($filesize > 0) {
                clearstatcache(true, $file);
                $content = fread($fp, $filesize);
            } else {
                $content = '';
            }
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if (empty($content)) {
                return [];
            }
            
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            } else {
                return [];
            }
        } else {
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            return [];
        }
    }
    return [];
}

function logError($message) {
    $logFile = 'error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    @error_log($logMessage, 3, $logFile);
}

// --- PDF EXPORT MODE ---
if (isset($_GET['mode']) && $_GET['mode'] === 'pdf' && isset($_SESSION['is_admin'])) {
    $file = 'daten.json';
    $data = safeReadJson($file);
    
    $pdf_labels = [
        'bildung' => 'Bildung & Schule',
        'social' => 'Verantwortung Social Media',
        'individuell' => 'Individuelle Verantwortung',
        'politik' => 'Politik & Recht',
        'kreativ' => 'Kreative & innovative Ansätze'
    ];
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Strategie Protokoll | PDF Export</title>
        <link href="https://fonts.googleapis.com/css2?family=Cardo:wght@700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; color: #111; line-height: 1.5; padding: 40px; max-width: 900px; margin: 0 auto; }
            h1 { font-family: 'Cardo', serif; font-size: 2.5rem; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 5px; }
            .meta { color: #666; font-size: 0.9rem; margin-bottom: 3rem; }
            .section { margin-bottom: 3rem; page-break-inside: avoid; }
            .section-title { font-size: 1.2rem; text-transform: uppercase; letter-spacing: 2px; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 1.5rem; color: #333; }
            .entry { margin-bottom: 1.5rem; padding-left: 15px; border-left: 3px solid #eee; }
            .entry-text { font-size: 1rem; margin-bottom: 5px; }
            .entry-meta { font-size: 0.75rem; color: #888; }
            .no-data { color: #999; font-style: italic; }
            @media print { body { padding: 0; } .no-print { display: none; } }
        </style>
    </head>
    <body onload="window.print()">
        <h1>Strategische Gegenmaßnahmen</h1>
        <div class="meta">Workshop Ergebnisse • Generiert am <?= date('d.m.Y \u\m H:i') ?> Uhr</div>

        <?php foreach ($pdf_labels as $key => $label): ?>
            <div class="section">
                <div class="section-title"><?= $label ?></div>
                <?php 
                $hasEntries = false;
                foreach ($data as $entry) {
                    if (($entry['thema'] ?? '') === $key) {
                        $hasEntries = true;
                        $status = ($entry['visible'] ?? false) ? "LIVE" : "ENTWURF";
                        echo '<div class="entry">';
                        echo '<div class="entry-text">' . nl2br(htmlspecialchars($entry['text'])) . '</div>';
                        echo '<div class="entry-meta">' . date('H:i', $entry['zeit']) . ' Uhr • Status: ' . $status . '</div>';
                        echo '</div>';
                    }
                }
                if (!$hasEntries) echo '<div class="no-data">Keine Einträge vorhanden.</div>';
                ?>
            </div>
        <?php endforeach; ?>
    </body>
    </html>
    <?php
    exit;
}

// --- LOGIN & LOGOUT ---
if (isset($_POST['login'])) {
    if ($_POST['password'] === $admin_passwort) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
    } else {
        $error = "ACCESS DENIED";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// --- ACTION HANDLER (ATOMIC) ---
$file = 'daten.json';

if (isset($_SESSION['is_admin'])) {
    $is_ajax = isset($_REQUEST['ajax']);
    $req = $_REQUEST;

    // 🔒 DELETE SINGLE ENTRY
    if (isset($req['delete'])) {
        $id = $req['delete'];
        atomicUpdate($file, function($data) use ($id) {
            return array_values(array_filter($data, fn($e) => $e['id'] !== $id));
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 DELETE ALL
    if (isset($req['deleteall']) && $req['deleteall'] === 'confirm') {
        atomicUpdate($file, function($data) {
            return [];
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 TOGGLE VISIBILITY
    if (isset($req['toggle_id'])) {
        $id = $req['toggle_id'];
        atomicUpdate($file, function($data) use ($id) {
            foreach ($data as &$entry) {
                if ($entry['id'] === $id) {
                    $entry['visible'] = !($entry['visible'] ?? false);
                }
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 TOGGLE FOCUS (nur EIN Eintrag kann focused sein)
    if (isset($req['toggle_focus'])) {
        $id = $req['toggle_focus'];
        atomicUpdate($file, function($data) use ($id) {
            foreach ($data as &$entry) {
                if ($entry['id'] === $id) {
                    $currentFocus = $entry['focus'] ?? false;
                    $entry['focus'] = !$currentFocus;
                } else {
                    $entry['focus'] = false; // Alle anderen ausschalten
                }
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 MOVE TO DIFFERENT THEMA
    if (isset($req['action']) && $req['action'] === 'move' && isset($req['id']) && isset($req['new_thema'])) {
        $id = $req['id'];
        $new_thema = $req['new_thema'];
        atomicUpdate($file, function($data) use ($id, $new_thema) {
            foreach ($data as &$entry) {
                if ($entry['id'] === $id) {
                    $entry['thema'] = $new_thema;
                }
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 SHOW ALL IN COLUMN
    if (isset($req['action_col']) && $req['action_col'] === 'show' && isset($req['col'])) {
        $col = $req['col'];
        atomicUpdate($file, function($data) use ($col) {
            foreach ($data as &$entry) {
                if ($entry['thema'] === $col) {
                    $entry['visible'] = true;
                }
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 HIDE ALL IN COLUMN
    if (isset($req['action_col']) && $req['action_col'] === 'hide' && isset($req['col'])) {
        $col = $req['col'];
        atomicUpdate($file, function($data) use ($col) {
            foreach ($data as &$entry) {
                if ($entry['thema'] === $col) {
                    $entry['visible'] = false;
                }
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 SHOW ALL
    if (isset($req['action_all']) && $req['action_all'] === 'show') {
        atomicUpdate($file, function($data) {
            foreach ($data as &$entry) {
                $entry['visible'] = true;
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }

    // 🔒 HIDE ALL
    if (isset($req['action_all']) && $req['action_all'] === 'hide') {
        atomicUpdate($file, function($data) {
            foreach ($data as &$entry) {
                $entry['visible'] = false;
            }
            return $data;
        });
        if ($is_ajax) { echo "OK"; exit; }
    }
}

// Daten für Display laden (Read-Only)
$data = safeReadJson($file);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | DisinfoConsulting</title>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:wght@700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0a0a0a;
            --bg-card: #1a1a1a;
            --text-main: #ffffff;
            --text-muted: #888888;
            --border-subtle: #333333;
            --accent-success: #00cc66;
            --accent-danger: #ff3333;
            --accent-warning: #ff9933;
            --font-heading: 'Cardo', serif;
            --font-body: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: var(--font-body);
            margin: 0; padding: 0;
            line-height: 1.6;
        }

        .mono-noise {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0.04; pointer-events: none; z-index: -1;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='1'/%3E%3C/svg%3E");
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }

        .login-wrapper {
            max-width: 400px; margin: 10vh auto; padding: 3rem;
            background: var(--bg-card); border: 1px solid var(--border-subtle);
        }
        .login-wrapper h1 { font-family: var(--font-heading); font-size: 2.5rem; margin: 0 0 1rem 0; text-align: center; }
        .login-wrapper input, .login-wrapper button { width: 100%; box-sizing: border-box; padding: 14px; font-size: 1rem; margin-bottom: 1rem; }
        .login-wrapper input { background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: white; }
        .login-wrapper input:focus { outline: none; border-color: white; }
        .error-msg { background: rgba(255,51,51,0.1); border-left: 3px solid var(--accent-danger); padding: 1rem; margin-bottom: 1rem; color: var(--accent-danger); }

        /* HEADER */
        .admin-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            border-bottom: 2px solid white; padding-bottom: 1rem; margin-bottom: 2rem;
            flex-wrap: wrap; gap: 1rem;
        }
        .admin-header h1 { font-family: var(--font-heading); font-size: 3rem; margin: 0; line-height: 1; }
        .subtitle { color: var(--text-muted); text-transform: uppercase; letter-spacing: 3px; font-size: 0.7rem; font-weight: 600; display: block; margin-bottom: 0.5rem; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn {
            padding: 10px 20px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3);
            color: white; text-decoration: none; font-weight: 600; letter-spacing: 0.5px;
            cursor: pointer; transition: 0.3s; font-size: 0.8rem; display: inline-block;
            text-align: center;
        }
        .btn:hover { background: white; color: black; }
        .btn-danger { background: rgba(255,51,51,0.2); border-color: var(--accent-danger); }
        .btn-danger:hover { background: var(--accent-danger); }
        .btn-success { background: rgba(0,204,102,0.2); border-color: var(--accent-success); }
        .btn-success:hover { background: var(--accent-success); color: black; }
        .btn-neutral { background: rgba(255,255,255,0.05); }
        .btn-sm { padding: 6px 12px; font-size: 0.75rem; }

        /* COMMAND PANEL */
        .command-panel {
            background: var(--bg-card); border: 1px solid var(--border-subtle);
            padding: 1.5rem; margin-bottom: 2rem;
        }
        .command-row { display: flex; gap: 2rem; align-items: flex-start; }
        .command-label { display: block; color: var(--text-muted); font-size: 0.7rem; font-weight: 600; letter-spacing: 1px; margin-bottom: 8px; }

        /* Global Buttons Layout */
        .global-btns { display: flex; gap: 5px; }

        /* Sector Layout */
        .sector-group { flex: 1; }
        .sector-container { display: flex; flex-wrap: wrap; width: 100%; gap: 4px; }

        .sector-ctrl {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle);
        }
        .sector-label { font-weight: 600; font-size: 0.75rem; color: var(--text-muted); }
        .st-btn { cursor: pointer; padding: 2px 8px; font-size: 0.7rem; font-weight: 600; transition: 0.2s; user-select: none; }
        .btn-on { color: var(--accent-success); }
        .btn-on:hover, .btn-on.active-on { background: var(--accent-success); color: black; }
        .btn-off { color: var(--accent-danger); }
        .btn-off:hover, .btn-off.active-off { background: var(--accent-danger); color: white; }

        /* FEED GRID */
        #admin-feed {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem;
        }

        .admin-card {
            background: var(--bg-card); border: 1px solid var(--border-subtle);
            padding: 1rem; transition: 0.3s; position: relative;
        }
        .admin-card.status-live { border-left: 3px solid var(--accent-success); }
        .admin-card.status-hidden { border-left: 3px solid var(--border-subtle); }

        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .admin-select { padding: 4px 8px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: white; font-size: 0.75rem; max-width: 65%; }
        .card-time { font-size: 0.7rem; color: var(--text-muted); }
        .card-body { font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.4; min-height: 40px; word-wrap: break-word; }
        .card-actions { display: flex; gap: 6px; }
        .card-actions .btn { flex: 1; padding: 8px; font-size: 0.7rem; }

        .btn-focus { background: rgba(255,153,51,0.2); border-color: var(--accent-warning); }
        .btn-focus:hover { background: var(--accent-warning); color: black; }
        .btn-focus.is-focused { background: var(--accent-warning); color: black; font-weight: bold; }
        
        .feed-header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid white; padding-bottom: 10px; flex-wrap: wrap; gap: 10px; }

        /* =========================================
           MOBILE RESPONSIVENESS
           ========================================= */
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            
            /* Header Stack */
            .admin-header { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .header-actions { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .header-actions .btn { width: 100%; box-sizing: border-box; }
            /* Make Logout button full width on last row if odd number of buttons */
            .header-actions .btn:last-child:nth-child(odd) { grid-column: span 2; }

            /* Login Form */
            .login-wrapper { width: 100%; margin: 2rem 0; box-sizing: border-box; padding: 1.5rem; }

            /* Command Panel Stack */
            .command-row { flex-direction: column; gap: 1.5rem; }
            .command-row > div { width: 100%; } /* Force Sections to Full Width */
            
            /* Global Actions (All Live/Hide) Full Width */
            .global-btns { width: 100%; gap: 10px; }
            .global-btns .btn { flex: 1; }

            /* Sector Controls Full Width */
            .sector-container { display: flex; flex-direction: column; gap: 8px; width: 100%; }
            .sector-ctrl { 
                display: flex; justify-content: space-between; width: 100%; 
                box-sizing: border-box; margin: 0; padding: 12px;
            }
            .st-btn { padding: 4px 12px; font-size: 0.85rem; } /* Larger touch targets */
            
            /* Feed adjustments */
            #admin-feed { grid-template-columns: 1fr; } /* Single Column on Mobile */
            .feed-header { flex-direction: column; align-items: flex-start; }
            .admin-select { max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="mono-noise"></div>

<div class="container">
    <?php if (!isset($_SESSION['is_admin'])): ?>
        <div class="login-wrapper">
            <h1>ADMIN ACCESS</h1>
            <?php if (isset($error)): ?>
                <div class="error-msg">⚠️ <?= $error ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="password" required autofocus placeholder="••••••••">
                <button type="submit" name="login" class="btn">UNLOCK</button>
            </form>
        </div>
    <?php else: ?>

        <header class="admin-header">
            <div>
                <span class="subtitle">Backend Control</span>
                <h1>Moderation</h1>
            </div>
            <div class="header-actions">
                <a href="admin.php?mode=pdf" target="_blank" class="btn">PDF Export</a>
                <a href="index.php" target="_blank" class="btn">View Live</a>
                <a href="admin.php?logout=1" class="btn btn-danger">Logout</a>
            </div>
        </header>

        <div class="command-panel">
            <h3 style="margin: 0 0 1rem 0; font-size: 0.9rem; text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;">Mass Control</h3>
            
            <div class="command-row">
                <div>
                    <span class="command-label">GLOBAL ACTION</span>
                    <div class="global-btns">
                        <button onclick="if(confirm('ALLES Live schalten?')) runCmd('action_all=show')" class="btn btn-sm btn-success" style="flex:1">ALL LIVE</button>
                        <button onclick="if(confirm('ALLES verstecken?')) runCmd('action_all=hide')" class="btn btn-sm btn-neutral" style="flex:1">ALL HIDE</button>
                    </div>
                </div>
                
                <div class="sector-group">
                    <span class="command-label">FILTER & CONTROL SECTORS</span>
                    <div class="sector-container">
                        <?php foreach ($gruppen_labels as $key => $label): ?>
                            <div class="sector-ctrl" id="ctrl-<?= $key ?>">
                                <span class="sector-label"><?= strtoupper(substr($label,0,3)) ?></span>
                                <div>
                                    <span onclick="runCmd('action_col=show&col=<?= $key ?>')" class="st-btn btn-on">ON</span>
                                    <span style="color:#444; margin: 0 4px;">|</span>
                                    <span onclick="runCmd('action_col=hide&col=<?= $key ?>')" class="st-btn btn-off">OFF</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-bottom: 2rem;">
            <div class="feed-header">
                <h2 style="margin: 0;">Incoming Data Feed</h2>
                <div id="purge-btn-wrapper"></div>
            </div>
        </div>

        <div id="admin-feed">
             <div style="padding: 3rem; text-align: center; color: var(--text-muted); grid-column: 1 / -1;">Loading Data...</div>
        </div>

    <?php endif; ?>
</div>

<?php if (isset($_SESSION['is_admin'])): ?>
<script>
    const gruppenLabels = <?= json_encode($gruppen_labels) ?>;
    
    function renderAdmin(data) {
        const feed = document.getElementById('admin-feed');
        const purgeWrapper = document.getElementById('purge-btn-wrapper');
        
        if (data.length > 0) {
            purgeWrapper.innerHTML = `<button onclick="if(confirm('WARNING: PURGE ALL?')) runCmd('deleteall=confirm')" class="btn btn-danger" style="font-size: 0.7rem;">PURGE ALL</button>`;
        } else {
            purgeWrapper.innerHTML = '';
            feed.innerHTML = '<div style="padding: 3rem; text-align: center; color: var(--text-muted); grid-column: 1 / -1;">NO DATA AVAILABLE</div>';
            return;
        }

        let html = '';
        const sectorCounts = {}; 

        Object.keys(gruppenLabels).forEach(k => sectorCounts[k] = { total: 0, visible: 0 });

        data.forEach(entry => {
            const isVisible = (entry.visible === true || entry.visible === "true");
            const isFocused = (entry.focus === true || entry.focus === "true");
            
            if(sectorCounts[entry.thema]) {
                sectorCounts[entry.thema].total++;
                if(isVisible) sectorCounts[entry.thema].visible++;
            }

            let optionsHtml = '';
            for (const [key, label] of Object.entries(gruppenLabels)) {
                const selected = (entry.thema === key) ? 'selected' : '';
                optionsHtml += `<option value="${key}" ${selected}>📂 ${label}</option>`;
            }
            
            const cardStatusClass = isVisible ? 'status-live' : 'status-hidden';
            const btnClass = isVisible ? 'btn-neutral' : 'btn-success';
            const btnText = isVisible ? 'HIDE' : 'GO LIVE';
            const focusClass = isFocused ? 'is-focused' : '';

            html += `
            <div class="admin-card ${cardStatusClass}" id="card-${entry.id}">
                <div class="card-header">
                    <select class="admin-select" onchange="runCmd('action=move&id=${entry.id}&new_thema='+this.value)">
                        ${optionsHtml}
                    </select>
                    <span class="card-time">${new Date(entry.zeit * 1000).toLocaleTimeString('de-DE', {hour: '2-digit', minute:'2-digit'})}</span>
                </div>
                
                <div class="card-body">${entry.text}</div>
                
                <div class="card-actions">
                    <button onclick="runCmd('toggle_focus=${entry.id}')" class="btn btn-focus ${focusClass}">FOCUS</button>
                    
                    <button onclick="runCmd('toggle_id=${entry.id}')" class="btn ${btnClass}">
                        ${btnText}
                    </button>
                    
                    <button onclick="if(confirm('Delete?')) runCmd('delete=${entry.id}')" class="btn btn-danger" style="flex: 0 0 auto;">✕</button>
                </div>
            </div>`;
        });
        
        feed.innerHTML = html;

        // Update Sector Button States
        Object.keys(sectorCounts).forEach(key => {
            const ctrl = document.getElementById('ctrl-' + key);
            if(ctrl) {
                const stats = sectorCounts[key];
                const btnOn = ctrl.querySelector('.btn-on');
                const btnOff = ctrl.querySelector('.btn-off');
                
                btnOn.classList.remove('active-on');
                btnOff.classList.remove('active-off');

                if (stats.total > 0) {
                    if (stats.visible === stats.total) {
                        btnOn.classList.add('active-on');
                    } else if (stats.visible === 0) {
                        btnOff.classList.add('active-off');
                    }
                }
            }
        });
    }

    async function runCmd(queryParams) {
        document.body.style.cursor = 'wait';
        
        try {
            const response = await fetch('admin.php?' + queryParams + '&ajax=1');
            
            if (response.ok) {
                updateAdminBoard(); 
            } else {
                console.error("Server Error");
            }
        } catch (e) {
            console.error(e);
        } finally {
            document.body.style.cursor = 'default';
        }
    }

    function updateAdminBoard() {
        fetch('index.php?api=1')
            .then(response => response.json())
            .then(data => renderAdmin(data))
            .catch(err => console.error(err));
    }
    
    updateAdminBoard();
    setInterval(updateAdminBoard, 2000);

</script>
<?php endif; ?>
</body>
</html>