<?php
/**
 * KOLLISIONSSICHERE FILE-HANDLING FUNKTIONEN
 * Für Workshop mit 50+ gleichzeitigen Nutzern
 */

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
        @chmod($backupDir, 0777); // Sicherstellen dass Ordner beschreibbar ist
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
    
    // Optional: Backup-Info loggen (auskommentiert um Log nicht zu überfüllen)
    // logError("Backup created: $backupFile");
    
    // Alte Backups aufräumen - nur die letzten X behalten
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
        // Nach Datum sortieren (älteste zuerst)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Älteste löschen
        $toDelete = array_slice($backups, 0, count($backups) - $keepLast);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}

/**
 * SICHERE JSON-DATEI LESEN
 * Verwendet Shared Lock - mehrere können gleichzeitig lesen
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
            logError("Failed to open file for reading after $attempts attempts");
            return [];
        }
        
        // Shared lock für Lesen
        if (flock($fp, LOCK_SH)) {
            $filesize = filesize($file);
            if ($filesize > 0) {
                clearstatcache(true, $file); // Cache leeren für aktuelle Größe
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
                logError("JSON decode error: " . json_last_error_msg());
                return [];
            }
        } else {
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to acquire read lock after $attempts attempts");
            return [];
        }
    }
    return [];
}

/**
 * ATOMIC READ-MODIFY-WRITE
 * 🔒 Die gesamte Operation läuft unter EINEM exklusiven Lock!
 * Verhindert Race Conditions komplett.
 */
function atomicAddEntry($file, $newEntry, $maxRetries = 10, $retryDelay = 100000) {
    $attempts = 0;
    
    while ($attempts < $maxRetries) {
        $attempts++;
        
        // Datei öffnen/erstellen
        $fp = fopen($file, 'c+'); // c+ = read/write, erstellt wenn nötig
        if (!$fp) {
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to open file after $attempts attempts");
            return false;
        }
        
        // 🔒 EXKLUSIVER LOCK - Ab hier ist die Datei gesperrt!
        if (flock($fp, LOCK_EX)) {
            
            // SCHRITT 1: Lesen (unter Lock!)
            $filesize = filesize($file);
            if ($filesize > 0) {
                clearstatcache(true, $file);
                $content = fread($fp, $filesize);
                $data = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    logError("JSON decode error during atomic operation: " . json_last_error_msg());
                    $data = [];
                }
            } else {
                $data = [];
            }
            
            // SCHRITT 2: Modifizieren (unter Lock!)
            array_unshift($data, $newEntry);
            
            // SCHRITT 3: Schreiben (unter Lock!)
            ftruncate($fp, 0);
            rewind($fp);
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                logError("JSON encode error: " . json_last_error_msg());
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            $writeResult = fwrite($fp, $json);
            fflush($fp); // Sicherstellen dass Daten auf Disk geschrieben werden
            
            // 🔓 UNLOCK - Ab hier können andere wieder zugreifen
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if ($writeResult !== false) {
                // ✅ AUTO-BACKUP ERSTELLEN
                createAutoBackup($file);
                return true;
            } else {
                logError("Failed to write during atomic operation on attempt $attempts");
                if ($attempts < $maxRetries) {
                    usleep($retryDelay);
                    continue;
                }
                return false;
            }
            
        } else {
            // Lock fehlgeschlagen
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to acquire exclusive lock after $attempts attempts");
            return false;
        }
    }
    
    return false;
}

/**
 * ATOMIC UPDATE ENTRY
 * Für Admin-Funktionen (Toggle visibility, Focus, Delete, etc.)
 */
function atomicUpdateEntry($file, $entryId, $updateCallback, $maxRetries = 10, $retryDelay = 100000) {
    $attempts = 0;
    
    while ($attempts < $maxRetries) {
        $attempts++;
        
        $fp = fopen($file, 'c+');
        if (!$fp) {
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to open file for update after $attempts attempts");
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
                    logError("JSON decode error during update: " . json_last_error_msg());
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
            } else {
                $data = [];
            }
            
            // SCHRITT 2: Modifizieren mit Callback
            $modified = false;
            foreach ($data as $index => &$entry) {
                if ($entry['id'] === $entryId) {
                    $updateCallback($entry); // User-defined Änderung
                    $modified = true;
                    break;
                }
            }
            unset($entry); // Wichtig: Referenz aufheben
            
            if (!$modified) {
                // Entry nicht gefunden - trotzdem OK
                flock($fp, LOCK_UN);
                fclose($fp);
                return true;
            }
            
            // SCHRITT 3: Schreiben
            ftruncate($fp, 0);
            rewind($fp);
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                logError("JSON encode error during update: " . json_last_error_msg());
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            $writeResult = fwrite($fp, $json);
            fflush($fp);
            
            // 🔓 UNLOCK
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if ($writeResult !== false) {
                // ✅ AUTO-BACKUP ERSTELLEN
                createAutoBackup($file);
            }
            
            return ($writeResult !== false);
            
        } else {
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            logError("Failed to acquire exclusive lock for update after $attempts attempts");
            return false;
        }
    }
    
    return false;
}

/**
 * ATOMIC DELETE ENTRY
 */
function atomicDeleteEntry($file, $entryId, $maxRetries = 10, $retryDelay = 100000) {
    $attempts = 0;
    
    while ($attempts < $maxRetries) {
        $attempts++;
        
        $fp = fopen($file, 'c+');
        if (!$fp) {
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            return false;
        }
        
        if (flock($fp, LOCK_EX)) {
            $filesize = filesize($file);
            if ($filesize > 0) {
                clearstatcache(true, $file);
                $content = fread($fp, $filesize);
                $data = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return false;
                }
            } else {
                $data = [];
            }
            
            // Filtern (Entry entfernen)
            $data = array_filter($data, fn($entry) => $entry['id'] !== $entryId);
            $data = array_values($data); // Array neu indizieren
            
            ftruncate($fp, 0);
            rewind($fp);
            
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            
            $writeResult = fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            
            if ($writeResult !== false) {
                // ✅ AUTO-BACKUP ERSTELLEN
                createAutoBackup($file);
            }
            
            return ($writeResult !== false);
        } else {
            fclose($fp);
            if ($attempts < $maxRetries) {
                usleep($retryDelay);
                continue;
            }
            return false;
        }
    }
    
    return false;
}

/**
 * Error Logging
 */
function logError($message) {
    $logFile = 'error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, $logFile);
}

/**
 * FILE INITIALIZATION
 * Sicherstellen dass Datei existiert und beschreibbar ist
 */
function ensureFileExists($file) {
    if (!file_exists($file)) {
        $success = file_put_contents($file, '[]');
        if ($success === false) {
            logError("Failed to create initial file: $file");
            return false;
        }
        @chmod($file, 0666); // Schreibrechte setzen (@ unterdrückt Fehler falls nicht erlaubt)
    }
    
    if (!is_writable($file)) {
        logError("File is not writable: $file");
        return false;
    }
    
    return true;
}