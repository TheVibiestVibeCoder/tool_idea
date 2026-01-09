<?php
// index.php
session_start(); // Session starten um Admin-Status zu prüfen

// Gruppen Definition
$gruppen = [
    'bildung' => ['title' => 'BILDUNG & FORSCHUNG', 'icon' => '📚'],
    'social' => ['title' => 'SOZIALE MEDIEN', 'icon' => '📱'],
    'individuell' => ['title' => 'INDIV. VERANTWORTUNG', 'icon' => '🧑'],
    'politik' => ['title' => 'POLITIK & RECHT', 'icon' => '⚖️'],
    'kreativ' => ['title' => 'INNOVATIVE ANSÄTZE', 'icon' => '💡']
];

// ===== VERBESSERTE FILE-HANDLING FUNKTIONEN =====

/**
 * Sichere JSON-Datei Lesen mit Retry-Logik
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
        
        // Shared lock für Lesen
        if (flock($fp, LOCK_SH)) {
            $filesize = filesize($file);
            if ($filesize > 0) {
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

$file = 'daten.json';
$data = safeReadJson($file);

// API Mode
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Prüfen ob Admin eingeloggt ist (für Context Menu Berechtigung)
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Situation Room | DisinfoConsulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        /* --- THEME ENGINE --- */
        :root {
            /* Default: DARK MODE */
            --bg-body: #050505;
            --bg-card: rgba(255, 255, 255, 0.03);
            --bg-card-hover: rgba(255, 255, 255, 0.12); /* Hellerer Hover */
            --border-subtle: rgba(255, 255, 255, 0.1);
            --border-hover: rgba(255, 255, 255, 0.6); /* Starker Rand Kontrast */
            --text-main: #ffffff;
            --text-muted: #a0a0a0;
            --card-shadow: none;
            --card-shadow-hover: 0 0 40px rgba(255, 255, 255, 0.15); /* Glow Effekt */
            --blur-color: rgba(255,255,255,0.5); 
            --spotlight-opacity: 1;
            
            /* Logo Filter Logik */
            --ep-logo-filter: brightness(0) invert(1); /* EP Logo (bunt) -> Weiß */
            --dc-logo-filter: none; /* DC Logo (weiß) -> Bleibt Weiß */
            
            --font-heading: 'Cardo', serif;
            --font-body: 'Inter', sans-serif;
        }

        /* LIGHT MODE OVERRIDES */
        body.light-mode {
            --bg-body: #f4f5f7;
            --bg-card: #ffffff;
            --bg-card-hover: #ffffff;
            --border-subtle: #dbe0e6;
            --border-hover: #a0a0a0;
            --text-main: #1a1a1a;
            --text-muted: #666666;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --card-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); /* Deep Shadow */
            --blur-color: rgba(0,0,0,0.4);
            --spotlight-opacity: 0.05;
            
            /* Logo Filter Logik Light Mode */
            --ep-logo-filter: none; /* EP Logo Original */
            --dc-logo-filter: invert(1); /* DC Logo (weiß) -> Schwarz */
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            margin: 0; padding: 0;
            overflow-x: hidden;
            transition: background-color 0.5s ease, color 0.5s ease;
        }

        .mono-noise {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0.06; pointer-events: none; z-index: -1;
            background-image: url("");
        }
        .spotlight {
            position: fixed; top: -50%; left: 50%; transform: translateX(-50%);
            width: 100vw; height: 100vw;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.05) 0%, transparent 70%);
            filter: blur(80px); pointer-events: none; z-index: -1;
            opacity: var(--spotlight-opacity);
            transition: opacity 0.5s ease;
        }

        /* --- TOOLBAR --- */
        .toolbar {
            position: absolute; top: 2rem; right: 2rem;
            display: flex; gap: 10px; z-index: 100;
        }
        .tool-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: 0.3s;
            text-decoration: none;
            font-size: 1.1rem;
        }
        .tool-btn:hover {
            border-color: var(--text-main);
            color: var(--text-main);
            transform: scale(1.05);
            background: var(--bg-card-hover);
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }

        .container { max-width: 1800px; margin: 0 auto; padding: 3rem; }

        .header-split {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 4rem; border-bottom: 1px solid var(--border-subtle); padding-bottom: 2rem;
            position: relative;
        }
        .subtitle { color: var(--text-muted); text-transform: uppercase; letter-spacing: 3px; font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 0.5rem; }
        
        /* LOGO ROW STYLES */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 1.5rem;
        }
        .ep-logo {
            height: 55px;
            width: auto;
            filter: var(--ep-logo-filter);
            transition: filter 0.5s ease;
        }
        .dc-logo {
            height: 55px;
            width: auto;
            filter: var(--dc-logo-filter);
            transition: filter 0.5s ease;
        }
        .logo-separator {
            color: var(--text-muted);
            font-size: 1.5rem;
            font-weight: 300;
            padding-top: 5px; /* Optische Korrektur */
        }

        /* OPTIMIERTER TITEL FÜR MOBILE */
        h1 { 
            font-family: var(--font-heading); 
            /* Reduzierte Mindestgröße von 2.5rem auf 1.8rem für kleine Screens */
            font-size: clamp(1.8rem, 6vw, 4rem); 
            margin: 0; 
            line-height: 1.1; 
            color: var(--text-main); 
            transition: color 0.5s ease;
            
            /* Sicherstellen, dass lange deutsche Wörter nicht überlaufen */
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            max-width: 100%;
        }
        
        /* QR SECTION (DESKTOP) */
        .qr-section { display: flex; align-items: center; gap: 1.5rem; cursor: pointer; transition: transform 0.2s; }
        .qr-section:hover { transform: scale(1.02); }
        .qr-text { text-align: right; color: var(--text-muted); font-size: 0.75rem; letter-spacing: 1px; line-height: 1.4; }
        .qr-wrapper { background: white; padding: 8px; border-radius: 4px; display: inline-block; box-shadow: 0 0 20px rgba(0,0,0,0.1); }

        /* MOBILE JOIN BUTTON (SUBTLE VERSION) */
        .mobile-join-btn {
            display: none; /* Standard: Ausgeblendet */
            
            /* Design analog zu Cards/Tools */
            background-color: var(--bg-card);
            border: 1px solid var(--border-subtle);
            color: var(--text-main);
            
            /* Typography */
            font-family: var(--font-body);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            text-decoration: none;
            
            /* Shape */
            padding: 12px 24px;
            border-radius: 4px; /* Passt besser zum Rest als rund */
            margin-top: 1rem;
            
            transition: all 0.3s ease;
            align-items: center;
            gap: 10px;
        }
        
        .mobile-join-btn:hover {
            background-color: var(--bg-card-hover);
            border-color: var(--text-main);
            transform: translateY(-2px);
        }

        /* OVERLAYS (QR & FOCUS) */
        .overlay {
            position: fixed; inset: 0; background: rgba(5,5,5,0.92);
            backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
            z-index: 9999; display: flex; align-items: center; justify-content: center; flex-direction: column;
            opacity: 0; pointer-events: none; transition: opacity 0.4s ease;
            cursor: pointer;
        }
        .overlay.active { opacity: 1; pointer-events: all; }
        
        .qr-overlay-content {
            background: white; padding: 40px; border-radius: 20px;
            box-shadow: 0 0 100px rgba(0,0,0,0.5); transform: scale(0.9); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
        }
        .overlay.active .qr-overlay-content { transform: scale(1); }
        .overlay-instruction { margin-top: 30px; color: var(--text-muted); font-family: var(--font-body); letter-spacing: 2px; text-transform: uppercase; font-size: 0.8rem; }

        /* FOCUS CARD STYLES */
        .focus-text {
            font-family: var(--font-heading);
            color: #ffffff; /* FIX: Immer Weiß, da Overlay immer dunkel ist */
            font-size: clamp(1.5rem, 4vw, 3.5rem);
            line-height: 1.3;
            max-width: 80%;
            text-align: center;
            transform: translateY(20px);
            transition: transform 0.4s ease;
        }
        .overlay.active .focus-text { transform: translateY(0); }

        /* GRID */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 2rem;
            width: 100%;
        }
        
        .column { min-width: 0; }
        
        .column h2 {
            font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; color: var(--text-muted);
            border-bottom: 1px solid var(--border-subtle); padding-bottom: 1rem; margin: 0 0 1.5rem 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            transition: border-color 0.5s ease;
        }

        .idea-card-wrapper { margin-bottom: 1rem; perspective: 1000px; }

        /* CARD STYLES */
        .idea-card {
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-size: 0.95rem;
            line-height: 1.5;
            color: var(--text-main);
            box-shadow: var(--card-shadow);
        }

        .idea-card.animate-in { 
            animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .idea-card:hover {
            border-color: var(--border-hover);
            background: var(--bg-card-hover);
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-2px);
        }

        /* BLURRED STATE */
        .idea-card.blurred {
            color: var(--blur-color);
            cursor: default;
            filter: blur(5px);
        }
        .idea-card.blurred:hover {
            border-color: var(--border-subtle);
            background: var(--bg-card);
            box-shadow: var(--card-shadow);
            transform: none;
        }

        /* === ADMIN CONTEXT MENU STYLES === */
        .context-menu {
            position: absolute;
            z-index: 10000;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            border-radius: 6px;
            display: none;
            overflow: hidden;
            min-width: 160px;
            padding: 4px 0;
            backdrop-filter: blur(10px);
        }

        .context-menu-item {
            padding: 10px 16px;
            cursor: pointer;
            color: var(--text-main);
            font-size: 0.85rem;
            font-family: var(--font-body);
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .context-menu-item:hover {
            background: var(--bg-card-hover);
            color: #fff;
        }

        .context-menu-item.danger {
            color: #ff4444;
            border-top: 1px solid var(--border-subtle);
            margin-top: 4px;
            padding-top: 12px;
        }

        .context-menu-item.danger:hover {
            background: rgba(255, 68, 68, 0.1);
        }

        /* RESPONSIVE GRID */
        @media (max-width: 1400px) { .dashboard-grid { grid-template-columns: repeat(3, 1fr); } }
        
        @media (max-width: 900px) { 
            /* GRID Anpassung */
            .dashboard-grid { grid-template-columns: repeat(2, 1fr); gap: 1rem; }
            .container { padding: 2rem 1rem; }
            .header-split { flex-direction: column; align-items: flex-start; gap: 2rem; }
            
            /* MOBILE LOGO & QR CHANGES */
            .ep-logo, .dc-logo { height: 40px; }
            .logo-row { gap: 15px; }

            /* Hide QR, Show Button */
            .qr-section { display: none !important; }
            .mobile-join-btn { display: inline-flex; }
        }
        
        @media (max-width: 600px) { 
            .dashboard-grid { grid-template-columns: 1fr; }
            .toolbar { top: 1rem; right: 1rem; }
        }
    </style>
</head>
<body>

<div class="mono-noise"></div>
<div class="spotlight"></div>

<div class="toolbar">
    <button class="tool-btn" id="themeToggle" title="Toggle Theme">☀︎</button>
    <a href="admin.php" class="tool-btn" title="Admin Panel">⚙</a>
</div>

<div class="overlay" id="qrOverlay">
    <div class="qr-overlay-content">
        <div id="qrcodeBig"></div>
    </div>
    <div class="overlay-instruction">Click anywhere to close</div>
</div>

<div class="overlay" id="focusOverlay">
    <div class="focus-text" id="focusContent"></div>
</div>

<?php if ($isAdmin): ?>
    <div id="customContextMenu" class="context-menu">
        <div class="context-menu-item" id="ctxToggle">👁 Einblenden/Ausblenden</div>
        <div class="context-menu-item danger" id="ctxDelete">🗑 Löschen</div>
    </div>
<?php endif; ?>

<div class="container">
    <header class="header-split">
        <div>
            <div class="logo-row">
                <img src="https://download-centre.europarl.europa.eu/files/live/sites/download-centre/files/thumbnails/ep-logo-monolingual-landscape.png" alt="European Parliament" class="ep-logo">
                <span class="logo-separator">×</span>
                <img src="https://disinfoconsulting.eu/wp-content/uploads/2025/12/DCweissstacked.svg" alt="DisinfoConsulting" class="dc-logo">
            </div>
            
            <span class="subtitle">Live Feed</span>
            <h1>Strategien<br>gegen Desinformation</h1>
            
            <a href="eingabe.php" class="mobile-join-btn">
               ↳ Eingabe starten
            </a>
        </div>
        
        <div class="qr-section" id="openQr">
            <div class="qr-text">SCAN TO<br>PARTICIPATE<br><span style="opacity: 0.5;">CLICK TO EXPAND</span></div>
            <div class="qr-wrapper" id="qrcodeSmall"></div>
        </div>
    </header>

    <div class="dashboard-grid" id="board">
        <?php foreach ($gruppen as $key => $info): ?>
            <div class="column" id="col-<?= $key ?>">
                <h2><?= $info['icon'] ?> <?= $info['title'] ?></h2>
                <div class="card-container">
                    </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    // --- THEME LOGIC ---
    const themeBtn = document.getElementById('themeToggle');
    const body = document.body;
    
    function updateIcon(isLight) {
        themeBtn.innerText = isLight ? '☾' : '☀︎';
    }

    if (localStorage.getItem('theme') === 'light') {
        body.classList.add('light-mode');
        updateIcon(true);
    }

    themeBtn.addEventListener('click', () => {
        body.classList.toggle('light-mode');
        const isLight = body.classList.contains('light-mode');
        localStorage.setItem('theme', isLight ? 'light' : 'dark');
        updateIcon(isLight);
    });

    // --- QR CODE LOGIC ---
    const currentUrl = window.location.href;
    const inputUrl = currentUrl.substring(0, currentUrl.lastIndexOf('/')) + '/eingabe.php';
    
    // 1. Small QR
    new QRCode(document.getElementById("qrcodeSmall"), { 
        text: inputUrl, width: 80, height: 80, 
        colorDark : "#000000", colorLight : "#ffffff", 
        correctLevel : QRCode.CorrectLevel.H 
    });

    // 2. Big QR
    const qrOverlay = document.getElementById('qrOverlay');
    const openQrBtn = document.getElementById('openQr');
    let bigQrGenerated = false;

    openQrBtn.addEventListener('click', () => {
        qrOverlay.classList.add('active');
        if (!bigQrGenerated) {
            new QRCode(document.getElementById("qrcodeBig"), { 
                text: inputUrl, width: 400, height: 400, 
                colorDark : "#000000", colorLight : "#ffffff", 
                correctLevel : QRCode.CorrectLevel.H 
            });
            bigQrGenerated = true;
        }
    });

    // --- FOCUS MODE LOGIC (Local + Remote) ---
    const focusOverlay = document.getElementById('focusOverlay');
    const focusContent = document.getElementById('focusContent');
    const board = document.getElementById('board');
    let remoteFocusActiveId = null; // Track if we are currently in a remote session

    // Local Click Event
    board.addEventListener('click', function(e) {
        const wrapper = e.target.closest('.idea-card-wrapper');
        
        if (wrapper) {
            const card = wrapper.querySelector('.idea-card');
            // Only open if the card is NOT blurred
            if (card && !card.classList.contains('blurred')) {
                const text = card.innerText;
                focusContent.innerText = text;
                focusOverlay.classList.add('active');
            }
        }
    });

    // Close overlays on click
    qrOverlay.addEventListener('click', () => qrOverlay.classList.remove('active'));
    
    focusOverlay.addEventListener('click', () => {
        focusOverlay.classList.remove('active');
        // If user manually closes it, we can technically reset our tracker, 
        // but the next polling might open it again if Admin hasn't turned it off.
        // This is acceptable behavior (admin enforces focus).
    });

    // --- CONTEXT MENU LOGIC (ADMIN ONLY) ---
    <?php if ($isAdmin): ?>
    (function() {
        const ctxMenu = document.getElementById('customContextMenu');
        const ctxToggle = document.getElementById('ctxToggle');
        const ctxDelete = document.getElementById('ctxDelete');
        let currentCardId = null;

        // Global click to close menu
        document.addEventListener('click', function(e) {
            if (!ctxMenu.contains(e.target)) {
                ctxMenu.style.display = 'none';
            }
        });

        // Right-click listener on cards
        document.addEventListener('contextmenu', function(e) {
            const wrapper = e.target.closest('.idea-card-wrapper');
            if (wrapper) {
                e.preventDefault();
                currentCardId = wrapper.getAttribute('data-id');
                const card = wrapper.querySelector('.idea-card');
                
                // Update text based on state
                const isHidden = card.classList.contains('blurred');
                ctxToggle.innerHTML = isHidden ? '👁 Einblenden' : '🚫 Ausblenden';

                // Position and show menu
                ctxMenu.style.display = 'block';
                ctxMenu.style.left = e.pageX + 'px';
                ctxMenu.style.top = e.pageY + 'px';
            } else {
                ctxMenu.style.display = 'none';
            }
        });

        // Handle Toggle
        ctxToggle.addEventListener('click', function() {
            if (currentCardId) {
                fetch('admin.php?toggle_id=' + currentCardId + '&ajax=1')
                    .then(() => updateBoard())
                    .catch(err => console.error(err));
                ctxMenu.style.display = 'none';
            }
        });

        // Handle Delete
        ctxDelete.addEventListener('click', function() {
            if (currentCardId && confirm('Diesen Eintrag wirklich löschen?')) {
                fetch('admin.php?delete=' + currentCardId + '&ajax=1')
                    .then(() => updateBoard())
                    .catch(err => console.error(err));
                ctxMenu.style.display = 'none';
            }
        });
    })();
    <?php endif; ?>


    // --- DATA HANDLING ---
    const gruppenConfig = <?= json_encode($gruppen) ?>;
    const initialData = <?= json_encode($data) ?>;
    
    renderData(initialData);

    function renderData(data) {
        const existingIds = new Set();
        document.querySelectorAll('.idea-card-wrapper').forEach(el => existingIds.add(el.getAttribute('data-id')));
        const validIdsInNewData = new Set();

        // 1. Check for Remote Focus first
        checkRemoteFocus(data);

        // 2. Render Cards
        data.forEach(entry => {
            validIdsInNewData.add(entry.id);
            const isVisible = (entry.visible === true || entry.visible === "true");
            const container = document.querySelector(`#col-${entry.thema} .card-container`);
            
            if (container) {
                let wrapper = document.getElementById('wrap-' + entry.id);
                let card;

                if (!wrapper) {
                    wrapper = document.createElement('div');
                    wrapper.id = 'wrap-' + entry.id;
                    wrapper.setAttribute('data-id', entry.id);
                    wrapper.className = 'idea-card-wrapper';
                    
                    card = document.createElement('div');
                    card.id = 'card-' + entry.id;
                    card.className = 'idea-card ' + (!isVisible ? 'blurred' : 'animate-in');
                    card.innerText = entry.text;
                    
                    wrapper.appendChild(card);
                    if(container.firstChild) {
                        container.insertBefore(wrapper, container.firstChild);
                    } else {
                        container.appendChild(wrapper);
                    }
                } else {
                    card = document.getElementById('card-' + entry.id);
                    if(card) {
                        if (!container.contains(wrapper)) container.prepend(wrapper);
                        
                        if (isVisible) {
                            card.classList.remove('blurred');
                        } else {
                            card.classList.add('blurred');
                        }
                        
                        if (card.innerText !== entry.text) card.innerText = entry.text;
                    }
                }
            }
        });

        existingIds.forEach(id => {
            if (!validIdsInNewData.has(id)) {
                const el = document.getElementById('wrap-' + id);
                if (el) el.remove();
            }
        });
    }

    function checkRemoteFocus(data) {
        // Find if any card has focus: true
        const focusedEntry = data.find(e => e.focus === true || e.focus === "true");

        if (focusedEntry) {
            // Admin wants this card focused
            focusContent.innerText = focusedEntry.text;
            focusOverlay.classList.add('active');
            remoteFocusActiveId = focusedEntry.id;
        } else {
            // No card is focused remotely.
            // If we were previously holding a remote focus open, we should close it now.
            if (remoteFocusActiveId !== null) {
                focusOverlay.classList.remove('active');
                remoteFocusActiveId = null;
            }
        }
    }

    function updateBoard() {
        fetch('index.php?api=1')
            .then(response => response.json())
            .then(data => renderData(data))
            .catch(err => console.error(err));
    }
    
    setInterval(updateBoard, 2000);
</script>
</body>
</html>