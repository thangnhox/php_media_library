<?php
/* --------------------------------------------------
   Detect base URL (Cloudflare safe)
-------------------------------------------------- */
$scheme = $_SERVER['HTTP_X_FORWARDED_PROTO']
    ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

$host = $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? $_SERVER['HTTP_HOST'];

$base_url = $scheme . '://' . $host;
$self = $_SERVER['SCRIPT_NAME'];
$dir_url = rtrim(dirname($self), '/');

/* --------------------------------------------------
   Params & Secrets Check
-------------------------------------------------- */
$format = strtolower($_GET['format'] ?? 'txt');

$exts = array_filter(
    array_map('strtolower', explode(',', $_GET['ext'] ?? ''))
);

$shuffle = isset($_GET['shuffle']) &&
           in_array(strtolower($_GET['shuffle']), ['1','true','yes'], true);

$search_query = strtolower($_GET['search'] ?? '');

$env_secret = getenv('PHP_SECRET_KEY');
if ($env_secret === false) {
    $env_secret = $_ENV['PHP_SECRET_KEY'] ?? $_SERVER['PHP_SECRET_KEY'] ?? '';
}
$ytdlp_enabled = !empty($env_secret);

/* --------------------------------------------------
   YT-DLP DOWNLOAD API
-------------------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'ytdlp') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!$ytdlp_enabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Downloader feature is disabled (Secret key not set).']);
        exit;
    }

    $postData = json_decode(file_get_contents('php://input'), true) ?? [];
    $provided_secret = $postData['secret'] ?? '';
    $yt_id = $postData['id'] ?? '';
    $dl_format = $postData['format'] ?? 'mp4';

    if ($provided_secret !== $env_secret) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid secret key.']);
        exit;
    }

    if (empty($yt_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'No YouTube ID or URL provided.']);
        exit;
    }

    // Sanitize input
    $target = escapeshellarg($yt_id);
    
    // Execute yt-dlp
    $out_tmpl = escapeshellarg(__DIR__ . '/%(title)s.%(ext)s');
    
    if ($dl_format === 'mp3') {
        // Best quality audio, extracted and converted to mp3, include sub
        $cmd = "yt-dlp -f bestaudio -x --audio-format mp3 --audio-quality 0 --write-subs --sub-langs \"en.*,-live_chat\" -o $out_tmpl $target 2>&1";
    } else {
        // Hard limit to best 720p vp9+aac, fallback to best 720p, merge to mp4 container, embed sub
        $f_args = "bestvideo[height<=720][vcodec*=vp9]+bestaudio[acodec*=aac]/bestvideo[height<=720][ext=webm]+bestaudio[ext=m4a]/bestvideo[height<=720]+bestaudio/best[height<=720]";
        $cmd = "yt-dlp -f \"$f_args\" --merge-output-format mp4 --write-subs --embed-subs --sub-langs \"en.*,-live_chat\" -o $out_tmpl $target 2>&1";
    }
    
    exec($cmd, $output, $return_var);

    if ($return_var !== 0) {
        http_response_code(500);
        echo json_encode(['error' => 'yt-dlp failed.', 'details' => implode("\n", $output)]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Download complete!']);
    exit;
}

/* --------------------------------------------------
   Scan directory once
-------------------------------------------------- */
$files = [];
$extensions = [];

foreach (scandir(__DIR__) as $f) {
    // if (!is_file($f)) continue;
    if (!is_file(__DIR__ . '/' . $f)) continue;

    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

    // Exclude PHP files completely
    if ($ext === 'php') continue;

    if ($ext !== '') {
        $extensions[$ext] = true;
    }

    $files[] = $f;
}

$extensions = array_keys($extensions);
sort($extensions);

/* --------------------------------------------------
   WEB PLAYER MODE (no ext provided)
-------------------------------------------------- */
if (empty($exts)) {
    header('Content-Type: text/html; charset=utf-8');

    // Generate direct JSON array for JavaScript to avoid fetch/CORS/parsing issues
    $media_extensions = ['mp4','mkv','webm','ogg','mp3','wav','flac','m4a','aac','m4v','mov','avi','wmv'];
    $sub_extensions = ['vtt', 'srt'];
    
    $js_media_files = [];
    $js_sub_files = [];
    
    foreach ($files as $f) {
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($e, $media_extensions, true)) {
            $js_media_files[] = $base_url . $dir_url . '/' . rawurlencode($f);
        } elseif (in_array($e, $sub_extensions, true)) {
            $js_sub_files[] = $base_url . $dir_url . '/' . rawurlencode($f);
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Media Player</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Custom Scrollbar for Dark Mode */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111827; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #4B5563; }
        
        /* Smooth fade for UI elements */
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* Hide scrollbar for category tabs */
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Performance for large lists */
        .playlist-item { content-visibility: auto; contain-intrinsic-size: 64px; }
        
        /* Custom Range Slider */
        input[type=range] { -webkit-appearance: none; background: transparent; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 12px; width: 12px; border-radius: 50%; background: #3b82f6; cursor: pointer; margin-top: -4px; box-shadow: 0 0 4px rgba(0,0,0,0.5); }
        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #4b5563; border-radius: 2px; }
        input[type=range]:focus { outline: none; }

        /* Subtitle (CC) Enhancement */
        video::cue {
            background-color: rgba(0, 0, 0, 0.75);
            color: #ffffff;
            font-size: 1.1rem;
            text-shadow: 0px 1px 3px rgba(0,0,0,1);
        }
        @media (min-width: 768px) {
            video::cue { font-size: 1.5rem; }
        }
    </style>
</head>
<body class="bg-gray-950 text-gray-200 font-sans h-screen w-screen overflow-hidden flex flex-col md:flex-row selection:bg-blue-500/30">

    <!-- Left/Top Side: Player Area -->
    <div class="w-full md:w-auto md:flex-1 flex flex-col bg-black relative z-10 shadow-2xl shrink-0">
        <!-- Media Player Container -->
        <div id="video-container" class="w-full aspect-video md:aspect-auto md:flex-1 flex items-center justify-center bg-black relative group overflow-hidden">
            <video id="player" autoplay playsinline class="w-full h-full object-contain outline-none transition-all"></video>
            
            <!-- Custom Controls Overlay -->
            <div id="custom-controls" class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/95 via-black/60 to-transparent p-2 md:p-4 flex flex-col gap-2 transition-opacity duration-300 opacity-100">
                
                <!-- Progress Bar -->
                <div class="flex items-center gap-2">
                    <span id="time-current" class="text-[10px] md:text-xs font-mono text-gray-300 w-10 text-right">0:00</span>
                    <input type="range" id="seek-bar" value="0" step="0.1" class="flex-1 accent-blue-500">
                    <span id="time-total" class="text-[10px] md:text-xs font-mono text-gray-400 w-10">0:00</span>
                </div>

                <!-- Buttons -->
                <div class="flex items-center justify-between text-white mt-1">
                    <div class="flex items-center gap-4 md:gap-6 ml-2">
                        <button id="btn-prev" class="hover:text-blue-400 transition text-sm md:text-base" title="Previous"><i class="fas fa-step-backward"></i></button>
                        <button id="btn-play-pause" class="hover:text-blue-400 transition text-xl md:text-2xl w-6 flex justify-center"><i class="fas fa-pause"></i></button>
                        <button id="btn-next" class="hover:text-blue-400 transition text-sm md:text-base" title="Next"><i class="fas fa-step-forward"></i></button>
                        <button id="btn-loop" class="hover:text-blue-400 transition text-sm md:text-base text-gray-400 relative" title="Loop: Off"><i class="fas fa-redo"></i></button>
                        
                        <!-- Volume -->
                        <div class="flex items-center gap-2 group/vol ml-2 md:ml-4">
                            <button id="btn-mute" class="hover:text-blue-400 transition text-sm md:text-base w-5 flex justify-center"><i class="fas fa-volume-up"></i></button>
                            <input type="range" id="volume-bar" min="0" max="1" step="0.05" value="1" class="w-16 md:w-20 hidden md:block group-hover/vol:block accent-blue-500">
                        </div>
                    </div>
                    
                    <div class="mr-2 flex items-center gap-4">
                        <div class="relative flex items-center">
                            <button id="btn-cc" class="hidden hover:text-blue-400 transition text-sm md:text-base text-gray-400" title="Subtitles"><i class="fas fa-closed-captioning"></i></button>
                            <div id="cc-menu" class="absolute bottom-full right-[-10px] mb-3 bg-gray-900/95 backdrop-blur border border-gray-700 rounded shadow-xl hidden flex-col min-w-[100px] max-w-[200px] max-h-[200px] overflow-y-auto no-scrollbar z-50 py-1">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        <button id="btn-fullscreen" class="hover:text-blue-400 transition text-sm md:text-base"><i class="fas fa-expand"></i></button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Now Playing Header -->
        <div class="p-3 md:p-6 bg-gray-900 border-t border-gray-800 flex flex-col justify-center min-h-[60px] md:min-h-[100px]">
            <div class="flex items-center justify-between mb-0.5 md:mb-1">
                <p class="text-[10px] md:text-xs font-bold text-blue-500 uppercase tracking-wider">Now Playing</p>
                <button id="btn-locate" class="hidden text-gray-400 hover:text-blue-400 transition-colors text-xs md:text-sm" title="Locate playing file in list">
                    <i class="fas fa-crosshairs"></i>
                </button>
            </div>
            <h2 id="now-playing" class="text-sm md:text-xl font-semibold text-white truncate drop-shadow-md">
                Select a file to play
            </h2>
        </div>
    </div>

    <!-- Right/Bottom Side: Sidebar & Playlist -->
    <div class="w-full md:w-[400px] flex-1 flex flex-col bg-gray-900 border-t md:border-t-0 md:border-l border-gray-800 shadow-xl z-20 min-h-0">
        
        <!-- Header & Search -->
        <div class="p-2 md:p-4 border-b border-gray-800 bg-gray-900 shrink-0">
            <div class="flex items-center justify-between mb-2 md:mb-3">
                <h1 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-play-circle text-blue-500"></i> Media Library
                </h1>
                <div class="flex items-center gap-3">
                    <?php if ($ytdlp_enabled): ?>
                    <button id="btn-ytdlp-open" class="text-blue-500 hover:text-blue-400 transition-colors text-xs md:text-sm" title="Download from YouTube">
                        <i class="fas fa-cloud-download-alt"></i>
                    </button>
                    <?php endif; ?>
                    <button id="btn-reload" class="text-gray-400 hover:text-blue-400 transition-colors text-xs md:text-sm" title="Reload Playlist">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                <input type="text" id="search-input" placeholder="Search files..." 
                    class="w-full bg-gray-950 border border-gray-700 rounded-lg py-1.5 md:py-2 pl-9 pr-4 text-xs md:text-sm text-gray-200 placeholder-gray-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-all">
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-gray-800 bg-gray-900/50 shrink-0">
            <button id="tab-all" class="flex-1 py-2 md:py-3 text-xs md:text-sm font-medium border-b-2 border-blue-500 text-blue-400 transition-colors bg-gray-800/20">
                All Media
            </button>
            <button id="tab-recent" class="flex-1 py-2 md:py-3 text-xs md:text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 transition-colors">
                Recent
            </button>
        </div>

        <!-- Category Filter Tabs -->
        <div id="category-tabs" class="flex overflow-x-auto bg-gray-900 border-b border-gray-800 px-2 md:px-3 py-1.5 md:py-2 gap-1.5 md:gap-2 no-scrollbar shrink-0" style="display: none;">
            <!-- Populated by JS -->
        </div>

        <!-- Action Controls -->
        <div class="grid grid-cols-4 gap-1.5 p-2 border-b border-gray-800 bg-gray-900 shrink-0">
            <button id="btn-play-all" class="py-1.5 md:py-2 bg-blue-600 hover:bg-blue-500 text-white rounded flex flex-col md:flex-row items-center justify-center gap-1 md:gap-2 shadow-sm transition-colors" title="Play from beginning">
                <i class="fas fa-play text-[10px] md:text-sm"></i> 
                <span class="text-[9px] md:text-xs font-bold leading-none">Play All</span>
            </button>
            <button id="btn-random" class="py-1.5 md:py-2 bg-gray-800 hover:bg-gray-700 text-gray-200 rounded flex flex-col md:flex-row items-center justify-center gap-1 md:gap-2 shadow-sm transition-colors" title="Play a random track right now">
                <i class="fas fa-dice text-[10px] md:text-sm"></i> 
                <span class="text-[9px] md:text-xs font-bold leading-none">Random</span>
            </button>
            <button id="btn-shuffle" class="py-1.5 md:py-2 bg-gray-800 hover:bg-gray-700 text-gray-500 rounded flex flex-col md:flex-row items-center justify-center gap-1 md:gap-2 shadow-sm transition-colors" title="Toggle shuffle mode for continuous play">
                <i class="fas fa-random text-[10px] md:text-sm"></i> 
                <span class="text-[9px] md:text-xs font-bold leading-none">Shuffle</span>
            </button>
            <div class="relative w-full h-full">
                <button id="btn-copy-link" class="w-full h-full py-1.5 md:py-2 bg-purple-600/80 hover:bg-purple-500 text-white rounded flex flex-col md:flex-row items-center justify-center gap-1 md:gap-2 shadow-sm transition-colors" title="Copy playlist link to clipboard">
                    <i class="fas fa-link text-[10px] md:text-sm"></i> 
                    <span class="text-[9px] md:text-xs font-bold leading-none">Copy Link</span>
                </button>
                <div id="copy-menu" class="absolute bottom-full right-0 mb-2 bg-gray-900/95 backdrop-blur border border-gray-700 rounded shadow-xl hidden flex-col min-w-[120px] overflow-hidden z-50">
                    <button class="copy-option px-3 py-2 text-left text-xs font-bold text-gray-300 hover:bg-blue-600 hover:text-white transition-colors border-b border-gray-800" data-format="m3u">.M3U Playlist</button>
                    <button class="copy-option px-3 py-2 text-left text-xs font-bold text-gray-300 hover:bg-blue-600 hover:text-white transition-colors border-b border-gray-800" data-format="m3u8">.M3U8 Playlist</button>
                    <button class="copy-option px-3 py-2 text-left text-xs font-bold text-gray-300 hover:bg-blue-600 hover:text-white transition-colors border-b border-gray-800" data-format="xspf">.XSPF Playlist</button>
                    <button class="copy-option px-3 py-2 text-left text-xs font-bold text-gray-300 hover:bg-blue-600 hover:text-white transition-colors" data-format="txt">Plain .TXT</button>
                </div>
            </div>
        </div>

        <!-- Playlist Container -->
        <div class="flex-1 overflow-y-auto relative p-1 md:p-2 pb-6 bg-gray-900" id="playlist-container">
            <div class="flex items-center justify-center h-full">
                <div class="animate-pulse flex flex-col items-center gap-3">
                    <i class="fas fa-spinner fa-spin text-2xl text-blue-500"></i>
                    <p class="text-gray-500 text-sm">Loading media...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- YT-DLP Download Modal -->
    <?php if ($ytdlp_enabled): ?>
    <div id="ytdlp-modal" class="fixed inset-0 bg-black/80 z-[100] flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
        <div class="bg-gray-900 border border-gray-700 rounded-xl p-5 md:p-6 w-[90%] max-w-md shadow-2xl transform scale-95 transition-transform duration-300">
            <h3 class="text-lg font-bold text-white mb-4"><i class="fas fa-cloud-download-alt text-blue-500 mr-2"></i>Download Media</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">YouTube ID or URL</label>
                    <input type="text" id="ytdlp-id" placeholder="e.g. dQw4w9WgXcQ" autocomplete="off" class="w-full bg-gray-950 border border-gray-700 rounded-lg py-2 px-3 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Admin Secret Key</label>
                    <input type="password" id="ytdlp-secret" placeholder="••••••••" class="w-full bg-gray-950 border border-gray-700 rounded-lg py-2 px-3 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Format</label>
                    <select id="ytdlp-format" class="w-full bg-gray-950 border border-gray-700 rounded-lg py-2 px-3 text-sm text-gray-200 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        <option value="mp4">Video (MP4, up to 720p VP9+AAC)</option>
                        <option value="mp3">Audio (MP3, Best Quality)</option>
                    </select>
                </div>
                
                <div id="ytdlp-status" class="text-xs hidden rounded p-2"></div>
                
                <div class="flex justify-end gap-3 mt-6">
                    <button id="btn-ytdlp-cancel" class="px-4 py-2 rounded text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition">Cancel</button>
                    <button id="btn-ytdlp-submit" class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold flex items-center gap-2 shadow transition">
                        <i class="fas fa-download"></i> Download
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const player = document.getElementById('player');
        const videoContainer = document.getElementById('video-container');
        const customControls = document.getElementById('custom-controls');
        const seekBar = document.getElementById('seek-bar');
        const volumeBar = document.getElementById('volume-bar');
        const btnPlayPause = document.getElementById('btn-play-pause');
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');
        const btnLoop = document.getElementById('btn-loop');
        const btnMute = document.getElementById('btn-mute');
        const btnFullscreen = document.getElementById('btn-fullscreen');
        const btnCc = document.getElementById('btn-cc');
        const ccMenu = document.getElementById('cc-menu');
        const timeCurrent = document.getElementById('time-current');
        const timeTotal = document.getElementById('time-total');
        const btnLocate = document.getElementById('btn-locate');

        const tabAll = document.getElementById('tab-all');
        const tabRecent = document.getElementById('tab-recent');
        const container = document.getElementById('playlist-container');
        const nowPlaying = document.getElementById('now-playing');
        const searchInput = document.getElementById('search-input');
        const categoryTabs = document.getElementById('category-tabs');
        const btnReload = document.getElementById('btn-reload');
        
        const btnPlayAll = document.getElementById('btn-play-all');
        const btnRandom = document.getElementById('btn-random');
        const btnShuffle = document.getElementById('btn-shuffle');
        const btnCopyLink = document.getElementById('btn-copy-link');
        const copyMenu = document.getElementById('copy-menu');
        const copyOptions = document.querySelectorAll('.copy-option');

        // Initialize allMedia directly from PHP to fix detection bugs
        let allMedia = <?= json_encode($js_media_files, JSON_UNESCAPED_SLASHES) ?>;
        let allSubs = <?= json_encode($js_sub_files, JSON_UNESCAPED_SLASHES) ?>;
        
        let recentMedia = JSON.parse(localStorage.getItem('webplayer_recent') || '[]');
        let currentTab = 'all';
        let currentPlayingUrl = '';
        let searchQuery = '';
        let isShuffle = false;
        let currentPlaylistOrder = []; // Tracks actual playback sequence
        let activeCategory = 'all';
        let currentlyPlayingElement = null; // tracks the active DOM element
        let controlsTimeout;
        let loopMode = 0; // 0 = Off, 1 = All, 2 = Current

        // --- Custom Player Logic ---
        
        function formatTime(seconds) {
            if (isNaN(seconds)) return "0:00";
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60);
            return `${m}:${s < 10 ? '0' : ''}${s}`;
        }

        function resetControlsTimeout() {
            if (customControls.classList.contains('opacity-0')) {
                customControls.classList.remove('opacity-0');
                customControls.classList.add('opacity-100');
            }
            clearTimeout(controlsTimeout);
            controlsTimeout = setTimeout(() => {
                if (!player.paused) {
                    customControls.classList.remove('opacity-100');
                    customControls.classList.add('opacity-0');
                }
            }, 3000);
        }

        let mouseMoveThrottle;
        videoContainer.addEventListener('mousemove', () => {
            if (mouseMoveThrottle) return;
            mouseMoveThrottle = setTimeout(() => { mouseMoveThrottle = null; }, 100);
            resetControlsTimeout();
        });
        
        videoContainer.addEventListener('touchstart', resetControlsTimeout, {passive: true});
        videoContainer.addEventListener('click', (e) => {
            if(e.target === player) togglePlayPause();
        });

        function togglePlayPause() {
            if (player.paused) player.play();
            else player.pause();
        }
        btnPlayPause.addEventListener('click', togglePlayPause);

        player.addEventListener('play', () => {
            btnPlayPause.innerHTML = '<i class="fas fa-pause"></i>';
            resetControlsTimeout();
        });
        
        player.addEventListener('pause', () => {
            btnPlayPause.innerHTML = '<i class="fas fa-play"></i>';
            customControls.classList.remove('opacity-0');
            customControls.classList.add('opacity-100');
            clearTimeout(controlsTimeout);
        });

        // Subtitles (CC) Menu Logic
        function renderCcMenu() {
            ccMenu.innerHTML = '';
            if (!player.textTracks || player.textTracks.length === 0) {
                btnCc.classList.add('hidden');
                ccMenu.classList.add('hidden');
                return;
            }
            btnCc.classList.remove('hidden');

            let isAnyShowing = false;
            for (let i = 0; i < player.textTracks.length; i++) {
                if (player.textTracks[i].mode === 'showing') isAnyShowing = true;
            }
            
            if (isAnyShowing) {
                btnCc.classList.add('text-blue-400');
                btnCc.classList.remove('text-gray-400');
            } else {
                btnCc.classList.remove('text-blue-400');
                btnCc.classList.add('text-gray-400');
            }

            const offBtn = document.createElement('button');
            offBtn.className = `px-3 py-1.5 text-left text-xs transition-colors whitespace-nowrap ${!isAnyShowing ? 'bg-blue-600/20 text-blue-400 font-bold border-l-2 border-blue-500' : 'text-gray-300 hover:bg-gray-800 border-l-2 border-transparent'}`;
            offBtn.innerText = 'Off';
            offBtn.onclick = (e) => { e.stopPropagation(); setTrack(-1); };
            ccMenu.appendChild(offBtn);

            for (let i = 0; i < player.textTracks.length; i++) {
                let track = player.textTracks[i];
                let isShowing = track.mode === 'showing';
                let btn = document.createElement('button');
                btn.className = `px-3 py-1.5 text-left text-xs transition-colors whitespace-nowrap truncate ${isShowing ? 'bg-blue-600/20 text-blue-400 font-bold border-l-2 border-blue-500' : 'text-gray-300 hover:bg-gray-800 border-l-2 border-transparent'}`;
                btn.innerText = track.label || `Track ${i + 1}`;
                btn.onclick = (e) => { e.stopPropagation(); setTrack(i); };
                ccMenu.appendChild(btn);
            }
        }

        function setTrack(index) {
            for (let i = 0; i < player.textTracks.length; i++) {
                player.textTracks[i].mode = (i === index) ? 'showing' : 'hidden';
            }
            ccMenu.classList.add('hidden');
            renderCcMenu(); 
        }

        if (player.textTracks) {
            player.textTracks.addEventListener('addtrack', renderCcMenu);
            player.textTracks.addEventListener('removetrack', renderCcMenu);
        }

        btnCc.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!player.textTracks || player.textTracks.length === 0) return;
            ccMenu.classList.toggle('hidden');
        });
        
        document.addEventListener('click', (e) => {
            if (!ccMenu.contains(e.target) && e.target !== btnCc) {
                ccMenu.classList.add('hidden');
            }
        });

        player.addEventListener('loadedmetadata', () => {
            seekBar.max = player.duration;
            timeTotal.textContent = formatTime(player.duration);
            setTimeout(renderCcMenu, 200); 
        });

        player.addEventListener('timeupdate', () => {
            if (!seekBar.matches(':active')) {
                seekBar.value = player.currentTime;
            }
            timeCurrent.textContent = formatTime(player.currentTime);
        });

        seekBar.addEventListener('input', () => {
            player.currentTime = seekBar.value;
        });

        const savedVol = localStorage.getItem('webplayer_volume');
        if (savedVol !== null) {
            player.volume = parseFloat(savedVol);
            volumeBar.value = savedVol;
            updateMuteIcon();
        }

        const savedLoop = localStorage.getItem('webplayer_loop');
        if (savedLoop !== null) {
            loopMode = parseInt(savedLoop);
        }
        updateLoopButton(); 

        function updateLoopButton() {
            if (loopMode === 0) {
                btnLoop.classList.remove('text-blue-400');
                btnLoop.classList.add('text-gray-400');
                btnLoop.title = "Loop: Off";
                btnLoop.innerHTML = '<i class="fas fa-redo"></i>';
            } else if (loopMode === 1) {
                btnLoop.classList.remove('text-gray-400');
                btnLoop.classList.add('text-blue-400');
                btnLoop.title = "Loop: All";
                btnLoop.innerHTML = '<i class="fas fa-redo"></i>';
            } else if (loopMode === 2) {
                btnLoop.classList.remove('text-gray-400');
                btnLoop.classList.add('text-blue-400');
                btnLoop.title = "Loop: Current";
                btnLoop.innerHTML = '<div class="relative"><i class="fas fa-redo"></i><span class="absolute -bottom-1 -right-1.5 text-[9px] font-black bg-gray-900 rounded px-[2px] leading-none">1</span></div>';
            }
        }

        btnLoop.addEventListener('click', () => {
            loopMode = (loopMode + 1) % 3;
            localStorage.setItem('webplayer_loop', loopMode);
            updateLoopButton();
        });

        volumeBar.addEventListener('input', () => {
            player.volume = volumeBar.value;
            player.muted = false;
            updateMuteIcon();
        });

        btnMute.addEventListener('click', () => {
            player.muted = !player.muted;
            updateMuteIcon();
        });

        function updateMuteIcon() {
            if (player.muted || player.volume === 0) {
                btnMute.innerHTML = '<i class="fas fa-volume-mute text-red-400"></i>';
            } else if (player.volume < 0.5) {
                btnMute.innerHTML = '<i class="fas fa-volume-down"></i>';
            } else {
                btnMute.innerHTML = '<i class="fas fa-volume-up"></i>';
            }
        }

        player.addEventListener('volumechange', () => {
            localStorage.setItem('webplayer_volume', player.volume);
            if(!player.muted) volumeBar.value = player.volume;
            updateMuteIcon();
        });

        btnFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                videoContainer.requestFullscreen().catch(err => console.error(err));
            } else {
                document.exitFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', () => {
            if (document.fullscreenElement) {
                btnFullscreen.innerHTML = '<i class="fas fa-compress"></i>';
            } else {
                btnFullscreen.innerHTML = '<i class="fas fa-expand"></i>';
            }
        });

        function playNext(manual = false) {
            if (currentPlaylistOrder.length === 0) return;
            
            if (!manual && loopMode === 2) {
                player.currentTime = 0;
                player.play();
                return;
            }

            if (isShuffle) {
                if (currentPlaylistOrder.length > 1) {
                    let randomIndex;
                    do {
                        randomIndex = Math.floor(Math.random() * currentPlaylistOrder.length);
                    } while (currentPlaylistOrder[randomIndex] === currentPlayingUrl);
                    playMedia(currentPlaylistOrder[randomIndex]);
                } else {
                    player.currentTime = 0;
                    player.play();
                }
            } else {
                let idx = currentPlaylistOrder.indexOf(currentPlayingUrl);
                if (idx >= 0 && idx + 1 < currentPlaylistOrder.length) {
                    playMedia(currentPlaylistOrder[idx + 1]);
                } else if (loopMode === 1 && currentPlaylistOrder.length > 0) {
                    playMedia(currentPlaylistOrder[0]);
                }
            }
        }

        function playPrev() {
            if (currentPlaylistOrder.length === 0) return;
            
            if (player.currentTime > 3) {
                player.currentTime = 0;
                player.play();
                return;
            }

            let idx = currentPlaylistOrder.indexOf(currentPlayingUrl);
            if (idx > 0) {
                playMedia(currentPlaylistOrder[idx - 1]);
            } else if (idx === 0 && currentPlaylistOrder.length > 0) {
                if (loopMode === 1) {
                    playMedia(currentPlaylistOrder[currentPlaylistOrder.length - 1]);
                } else {
                    player.currentTime = 0;
                    player.play();
                }
            }
        }

        btnNext.addEventListener('click', () => playNext(true));
        btnPrev.addEventListener('click', playPrev);
        player.addEventListener('ended', () => playNext(false));

        btnPlayAll.onclick = () => {
            if (currentPlaylistOrder.length > 0) playMedia(currentPlaylistOrder[0]);
        };

        btnRandom.onclick = () => {
            if (currentPlaylistOrder.length > 1) {
                let randomIndex;
                do {
                    randomIndex = Math.floor(Math.random() * currentPlaylistOrder.length);
                } while (currentPlaylistOrder[randomIndex] === currentPlayingUrl);
                playMedia(currentPlaylistOrder[randomIndex]);
            } else if (currentPlaylistOrder.length === 1) {
                playMedia(currentPlaylistOrder[0]);
            }
        };

        btnShuffle.onclick = () => {
            isShuffle = !isShuffle;
            if (isShuffle) {
                btnShuffle.classList.remove('text-gray-500', 'bg-gray-800', 'hover:bg-gray-700');
                btnShuffle.classList.add('text-blue-400', 'bg-blue-900/50', 'hover:bg-blue-900/70', 'border', 'border-blue-500/30');
            } else {
                btnShuffle.classList.add('text-gray-500', 'bg-gray-800', 'hover:bg-gray-700');
                btnShuffle.classList.remove('text-blue-400', 'bg-blue-900/50', 'hover:bg-blue-900/70', 'border', 'border-blue-500/30');
            }
        };

        btnCopyLink.addEventListener('click', (e) => {
            e.stopPropagation();
            copyMenu.classList.toggle('hidden');
        });

        copyOptions.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                copyMenu.classList.add('hidden');
                
                const format = btn.getAttribute('data-format');
                let extsToExport = activeCategory === 'all' 
                    ? 'mp4,mkv,webm,ogg,mp3,wav,flac,m4a,aac,m4v,mov,avi,wmv' 
                    : activeCategory;
                
                let apiUrl = window.location.origin + window.location.pathname + `?ext=${extsToExport}&format=${format}`;
                if (isShuffle) apiUrl += '&shuffle=1';
                if (searchQuery) apiUrl += `&search=${encodeURIComponent(searchQuery)}`;
                
                const tempInput = document.createElement('input');
                tempInput.value = apiUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);

                const originalHtml = btnCopyLink.innerHTML;
                btnCopyLink.innerHTML = '<i class="fas fa-check text-[10px] md:text-sm"></i> <span class="text-[9px] md:text-xs font-bold leading-none">Copied!</span>';
                btnCopyLink.classList.replace('bg-purple-600/80', 'bg-green-600');
                btnCopyLink.classList.replace('hover:bg-purple-500', 'hover:bg-green-500');
                
                setTimeout(() => {
                    btnCopyLink.innerHTML = originalHtml;
                    btnCopyLink.classList.replace('bg-green-600', 'bg-purple-600/80');
                    btnCopyLink.classList.replace('hover:bg-green-500', 'hover:bg-purple-500');
                }, 2000);
            });
        });

        document.addEventListener('click', (e) => {
            if (!copyMenu.contains(e.target) && e.target !== btnCopyLink) {
                copyMenu.classList.add('hidden');
            }
        });

        function getFilename(url) {
            try { return decodeURIComponent(url.split('/').pop()); } 
            catch(e) { return url; }
        }

        function getExtension(filename) {
            let parts = filename.split('.');
            return parts.length > 1 ? parts.pop().toLowerCase() : 'other';
        }

        function getFilteredList(list) {
            let filtered = list;
            if (currentTab === 'all' && activeCategory !== 'all') {
                filtered = filtered.filter(url => getExtension(getFilename(url)) === activeCategory);
            }
            if (searchQuery) {
                filtered = filtered.filter(url => getFilename(url).toLowerCase().includes(searchQuery.toLowerCase()));
            }
            return filtered;
        }

        function renderCategoryTabs() {
            categoryTabs.innerHTML = '';
            
            if (currentTab !== 'all') {
                categoryTabs.style.display = 'none';
                return;
            }

            let exts = new Set();
            allMedia.forEach(url => exts.add(getExtension(getFilename(url))));
            
            if (activeCategory !== 'all' && !exts.has(activeCategory)) {
                activeCategory = 'all';
            }

            let sortedExts = Array.from(exts).sort();

            if (sortedExts.length <= 1) {
                categoryTabs.style.display = 'none';
                return;
            }
            
            categoryTabs.style.display = 'flex';

            let allBtn = document.createElement('button');
            allBtn.className = `flex-shrink-0 px-3 py-1 rounded-full text-xs font-bold transition-colors border ${
                activeCategory === 'all' ? 'bg-blue-600 text-white border-blue-500' : 'bg-gray-800 text-gray-400 border-gray-700 hover:bg-gray-700'
            }`;
            allBtn.innerText = 'All';
            allBtn.onclick = () => { activeCategory = 'all'; renderCategoryTabs(); renderList(); };
            categoryTabs.appendChild(allBtn);

            sortedExts.forEach(ext => {
                let btn = document.createElement('button');
                btn.className = `flex-shrink-0 px-3 py-1 rounded-full text-xs font-bold transition-colors border ${
                    activeCategory === ext ? 'bg-blue-600 text-white border-blue-500' : 'bg-gray-800 text-gray-400 border-gray-700 hover:bg-gray-700'
                }`;
                btn.innerText = `.${ext}`;
                btn.onclick = () => { activeCategory = ext; renderCategoryTabs(); renderList(); };
                categoryTabs.appendChild(btn);
            });
        }

        function setElementActiveState(div, isPlaying) {
            if (isPlaying) {
                div.className = "playlist-item p-3 mb-1 rounded-lg cursor-pointer flex items-center gap-3 group border bg-blue-600/20 text-blue-100 border-blue-500/30";
                div.children[0].className = "w-10 h-10 rounded bg-gray-950 flex items-center justify-center flex-shrink-0 shadow-inner text-blue-400 border border-blue-500/30";
                div.children[0].children[0].className = "fas fa-play text-xs";
            } else {
                div.className = "playlist-item p-3 mb-1 rounded-lg cursor-pointer flex items-center gap-3 group border hover:bg-gray-800 border-transparent text-gray-300";
                div.children[0].className = "w-10 h-10 rounded bg-gray-950 flex items-center justify-center flex-shrink-0 shadow-inner text-gray-500 group-hover:text-blue-400 group-hover:border group-hover:border-gray-700";
                div.children[0].children[0].className = "fas fa-music text-xs";
            }
        }

        function createListItem(url) {
            let isPlaying = currentPlayingUrl === url;
            let filename = getFilename(url);

            let div = document.createElement('div');
            div.setAttribute('data-url', url);
            div.onclick = () => playMedia(url);

            div.innerHTML = `
                <div><i></i></div>
                <div class="truncate flex-1 text-sm font-medium leading-relaxed" title="${filename.replace(/"/g, '&quot;')}">
                    ${filename}
                </div>
            `;
            
            setElementActiveState(div, isPlaying);
            if (isPlaying) currentlyPlayingElement = div;

            return div;
        }

        function updateActiveItemState(newUrl) {
            if (currentlyPlayingElement) {
                setElementActiveState(currentlyPlayingElement, false);
            }
            
            let newEl = null;
            for (let el of container.children) {
                if (el.getAttribute('data-url') === newUrl) {
                    newEl = el;
                    break;
                }
            }
            
            if (newEl) {
                setElementActiveState(newEl, true);
                currentlyPlayingElement = newEl;
            } else {
                currentlyPlayingElement = null;
            }
        }

        function scrollToActiveItem(mode = false) {
            if (!currentlyPlayingElement) return;
            let forceCenter = mode === 'center' || mode === 'center_instant';
            let instant = mode === 'center_instant' || mode === 'instant';

            if (!instant) {
                const elTop = currentlyPlayingElement.offsetTop;
                const contTop = container.scrollTop;
                if (Math.abs(elTop - contTop) > container.clientHeight * 1.5) {
                    instant = true;
                }
            }

            setTimeout(() => {
                if (currentlyPlayingElement) {
                    currentlyPlayingElement.scrollIntoView({ 
                        behavior: instant ? 'auto' : 'smooth', 
                        block: forceCenter ? 'center' : 'nearest' 
                    });
                }
            }, 10);
        }

        btnLocate.addEventListener('click', () => {
            if (searchQuery !== '') {
                searchInput.value = '';
                searchQuery = '';
                renderList('center_instant');
            } else {
                if (!currentlyPlayingElement) renderList('center_instant');
                else scrollToActiveItem('center_instant');
            }
        });

        function renderList(scrollMode = false) {
            let previousScroll = container.scrollTop; 
            container.innerHTML = '';
            currentlyPlayingElement = null; 
            
            let baseList = currentTab === 'all' ? allMedia : recentMedia;
            let list = getFilteredList(baseList);
            currentPlaylistOrder = []; 

            if (list.length === 0) {
                container.innerHTML = `
                    <div class="h-full flex flex-col items-center justify-center text-gray-500 fade-in pt-10">
                        <i class="fas ${searchQuery ? 'fa-search-minus' : 'fa-folder-open'} text-4xl mb-4 opacity-50"></i>
                        <p class="text-sm font-medium">${searchQuery ? 'No results found.' : 'No media files here.'}</p>
                    </div>`;
                return;
            }

            const fragment = document.createDocumentFragment();
            list.forEach((url) => {
                currentPlaylistOrder.push(url);
                fragment.appendChild(createListItem(url));
            });
            container.appendChild(fragment); 

            requestAnimationFrame(() => {
                if (scrollMode && currentlyPlayingElement) {
                    scrollToActiveItem(scrollMode === 'center');
                } else {
                    container.scrollTop = previousScroll;
                }
            });
        }

        function playMedia(url) {
            player.innerHTML = '';
            
            let baseFilename = getFilename(url).replace(/\.[^/.]+$/, "");
            let matchingSubs = allSubs.filter(subUrl => {
                let subName = getFilename(subUrl);
                return subName === baseFilename + '.vtt' || 
                       subName === baseFilename + '.srt' ||
                       subName.startsWith(baseFilename + '.');
            });
            
            matchingSubs.forEach((subUrl, index) => {
                let track = document.createElement('track');
                track.src = subUrl;
                track.kind = 'subtitles';
                
                let subName = getFilename(subUrl);
                let ext = getExtension(subName).toUpperCase();
                let label = 'Sub ' + (index + 1);
                
                let langMatch = subName.substring(baseFilename.length);
                if (langMatch.startsWith('.') && langMatch.split('.').length > 2) {
                    let middle = langMatch.substring(1, langMatch.lastIndexOf('.'));
                    let langCode = middle.toUpperCase();
                    
                    let lastDashIndex = middle.lastIndexOf('-');
                    if (lastDashIndex > 0 && middle.length - lastDashIndex - 1 === 11) {
                        langCode = middle.substring(0, lastDashIndex).toUpperCase();
                    }
                    label = langCode; 
                } else {
                    label = ext; 
                }
                
                track.label = label;
                player.appendChild(track);
            });
            
            renderCcMenu();

            if (currentPlayingUrl !== url) {
                player.src = url;
                player.play().catch(e => console.error("Playback error:", e));
                currentPlayingUrl = url;
                nowPlaying.innerText = getFilename(url);
            } else {
                player.play(); 
            }

            recentMedia = recentMedia.filter(u => u !== url);
            recentMedia.unshift(url);
            if (recentMedia.length > 50) recentMedia.pop(); 
            localStorage.setItem('webplayer_recent', JSON.stringify(recentMedia));

            btnLocate.classList.remove('hidden');

            if (currentTab === 'all') {
                updateActiveItemState(url);
                requestAnimationFrame(() => scrollToActiveItem(false));
            } else {
                renderList(true);
            }
        }

        function switchTab(tab) {
            currentTab = tab;
            if (tab === 'all') {
                tabAll.className = "flex-1 py-3 text-sm font-medium border-b-2 border-blue-500 text-blue-400 transition-colors bg-gray-800/20";
                tabRecent.className = "flex-1 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 transition-colors bg-transparent";
            } else {
                tabRecent.className = "flex-1 py-3 text-sm font-medium border-b-2 border-blue-500 text-blue-400 transition-colors bg-gray-800/20";
                tabAll.className = "flex-1 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 transition-colors bg-transparent";
            }
            renderCategoryTabs();
            renderList();
        }

        tabAll.onclick = () => switchTab('all');
        tabRecent.onclick = () => switchTab('recent');

        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value;
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderList();
            }, 150);
        });

        btnReload.addEventListener('click', () => {
            const icon = btnReload.querySelector('i');
            icon.classList.add('fa-spin', 'text-blue-400');
            
            const mediaExts = 'mp4,mkv,webm,ogg,mp3,wav,flac,m4a,aac,m4v,mov,avi,wmv';
            const subExts = 'vtt,srt';
            const fetchUrl = `<?= htmlspecialchars($self) ?>?ext=${mediaExts},${subExts}&format=txt`;
            
            fetch(fetchUrl)
                .then(r => r.text())
                .then(txt => {
                    const files = txt.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                    allMedia = files.filter(f => {
                        const ext = getExtension(getFilename(f));
                        return !['vtt', 'srt'].includes(ext);
                    });
                    allSubs = files.filter(f => {
                        const ext = getExtension(getFilename(f));
                        return ['vtt', 'srt'].includes(ext);
                    });
                    
                    renderCategoryTabs();
                    renderList();
                })
                .catch(err => console.error("Reload failed:", err))
                .finally(() => {
                    setTimeout(() => icon.classList.remove('fa-spin', 'text-blue-400'), 500);
                });
        });

        // --- YT-DLP Download Logic ---
        const btnYtdlpOpen = document.getElementById('btn-ytdlp-open');
        if (btnYtdlpOpen) {
            const modal = document.getElementById('ytdlp-modal');
            const modalContent = modal.querySelector('div');
            const btnCancel = document.getElementById('btn-ytdlp-cancel');
            const btnSubmit = document.getElementById('btn-ytdlp-submit');
            const inputId = document.getElementById('ytdlp-id');
            const inputSecret = document.getElementById('ytdlp-secret');
            const statusDiv = document.getElementById('ytdlp-status');
            
            // Restore secret key from localstorage if present
            inputSecret.value = localStorage.getItem('ytdlp_secret') || '';

            const showModal = () => {
                modal.classList.remove('hidden');
                setTimeout(() => {
                    modal.classList.remove('opacity-0');
                    modalContent.classList.remove('scale-95');
                    inputId.focus();
                }, 10);
            };

            const hideModal = () => {
                modal.classList.add('opacity-0');
                modalContent.classList.add('scale-95');
                setTimeout(() => {
                    modal.classList.add('hidden');
                    statusDiv.classList.add('hidden');
                    inputId.value = '';
                }, 300);
            };

            btnYtdlpOpen.addEventListener('click', showModal);
            btnCancel.addEventListener('click', hideModal);

            btnSubmit.addEventListener('click', async () => {
                const id = inputId.value.trim();
                const secret = inputSecret.value.trim();
                const format = document.getElementById('ytdlp-format').value;

                if (!id || !secret) {
                    statusDiv.className = 'text-xs rounded p-3 bg-red-900/50 text-red-400 border border-red-800 mt-4';
                    statusDiv.innerText = 'Please provide both the YouTube ID/URL and your Admin Secret Key.';
                    statusDiv.classList.remove('hidden');
                    return;
                }

                localStorage.setItem('ytdlp_secret', secret);
                
                statusDiv.className = 'text-xs rounded p-3 bg-blue-900/50 text-blue-400 border border-blue-800 mt-4 flex items-center gap-2';
                statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Downloading and extracting media... this may take a while.';
                statusDiv.classList.remove('hidden');
                
                btnSubmit.disabled = true;
                btnSubmit.classList.add('opacity-50', 'cursor-not-allowed');
                btnCancel.disabled = true;

                try {
                    const res = await fetch('?action=ytdlp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id, secret, format })
                    });

                    const data = await res.json();

                    if (!res.ok) {
                        throw new Error(data.error || 'Failed to download.');
                    }

                    statusDiv.className = 'text-xs rounded p-3 bg-green-900/50 text-green-400 border border-green-800 mt-4';
                    statusDiv.innerText = data.message || 'Download complete!';
                    
                    // Refresh the library
                    btnReload.click();
                    
                    setTimeout(() => {
                        hideModal();
                        btnSubmit.disabled = false;
                        btnCancel.disabled = false;
                        btnSubmit.classList.remove('opacity-50', 'cursor-not-allowed');
                    }, 2000);

                } catch (err) {
                    statusDiv.className = 'text-xs rounded p-3 bg-red-900/50 text-red-400 border border-red-800 mt-4 break-words';
                    statusDiv.innerText = err.message;
                    btnSubmit.disabled = false;
                    btnCancel.disabled = false;
                    btnSubmit.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            });
        }

        // Init
        renderCategoryTabs();
        renderList();
    </script>
</body>
</html>
<?php
    exit;
}

/* --------------------------------------------------
   API MODE (ext provided)
-------------------------------------------------- */
$result = [];

foreach ($files as $f) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (in_array($ext, $exts, true)) {
        if ($search_query !== '' && strpos(strtolower($f), $search_query) === false) {
            continue;
        }
        
        $result[] = [
            'name' => $f,
            'url' => $base_url . $dir_url . '/' . rawurlencode($f)
        ];
    }
}

if ($shuffle) {
    shuffle($result);
}

/* --------------------------------------------------
   Output
-------------------------------------------------- */
if ($format === 'm3u' || $format === 'm3u8') {
    $dl_name = empty($exts) ? 'playlist' : implode('_', $exts);
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dl_name . '.' . $format . '"');
    echo "#EXTM3U\n";
    foreach ($result as $item) {
        echo "#EXTINF:-1," . $item['name'] . "\n";
        echo $item['url'] . "\n";
    }
} elseif ($format === 'xspf') {
    $dl_name = empty($exts) ? 'playlist' : implode('_', $exts);
    header('Content-Type: application/xspf+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dl_name . '.xspf"');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<playlist version=\"1\" xmlns=\"http://xspf.org/ns/0/\">\n";
    echo "  <trackList>\n";
    foreach ($result as $item) {
        echo "    <track>\n";
        echo "      <location>" . htmlspecialchars($item['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</location>\n";
        echo "      <title>" . htmlspecialchars($item['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</title>\n";
        echo "    </track>\n";
    }
    echo "  </trackList>\n";
    echo "</playlist>\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $urls = array_column($result, 'url');
    echo implode("\n", $urls);
}
