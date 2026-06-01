# Single-File Web Media Player & API

A lightweight, single-file PHP script that instantly turns any directory of media files into a fully-featured, responsive Web Media Player and an automated M3U playlist generator.

Just drop index.php into a folder containing your videos or music, and it will automatically scan, categorize, and serve them.

## 🚀 Features

- Zero Configuration: No database required. Drop the script into a directory, and it works instantly.
- Modern Web Player: A beautiful, responsive UI built with Tailwind CSS. Includes custom controls for video and audio playback.
- Smart Subtitles: Automatically detects and loads sidecar .vtt and .srt subtitles, complete with language tag recognition (e.g., movie.en.vtt).
- Dynamic Media Library: - Auto-generated category tabs based on available extensions (e.g., .mp4, .mp3, .mkv).
  - Live search filtering.
  - "Recent" tab to resume previously played media.
  - Background reloading (update the list without refreshing the page).
- Playback Controls: Shuffle, Random, Loop (All/Current), and Play All.
- M3U / API Generator: Generate dynamic playlists for external players (like VLC) via simple URL parameters.
- Cloudflare Safe: Built-in reverse proxy detection for accurate URL generation.
- yt-dlp Integration: Supports a secure API endpoint to download YouTube IDs that are not available in your library (protected by the PHP_SECRET_KEY environment variable).

## 🛠️ Installation & Usage

1. Download the index.php file directly, or clone the repository:

   ```
   https://github.com/thangnhox/php_media_library.git
   ```
2. Place it in a directory on your web server that contains your media files.
3. Access the directory in your web browser.

## 🐳 Docker Deployment

A Dockerfile is included to easily deploy the application with all its dependencies (Apache, PHP, yt-dlp, ffmpeg, etc.) pre-installed and configured.

### 1. Build the Docker Image

Open your terminal in the directory containing the Dockerfile and index.php, then run:

```
docker build -t web-media-player .
```

### 2. Run the Container

Run the container by mapping your local media directory to the web server and exposing port 80. You can also pass the PHP_SECRET_KEY to enable the yt-dlp download feature:

```
docker run -d \
  --name media-player \
  -p 8080:80 \
  -v /path/to/your/media:/var/www/html/media \
  -e PHP_SECRET_KEY="your_secure_password" \
  web-media-player
```

- Access the Player: Open http://localhost:8080 in your browser.
- Media Folder: Replace /path/to/your/media with the actual path to your video or music files on your host machine. They will appear in the /media subfolder on the web player.

## 🔌 API & Playlist Generator

You can generate dynamic playlists, text lists, or interact with backend features by appending query parameters to the URL.

### Parameters

| Parameter | Type    | Default | Description                                                                                                    |
|-----------|---------|---------|----------------------------------------------------------------------------------------------------------------|
| ext       | String  | (none)  | Required to trigger Playlist API mode. Comma-separated list of file extensions to include (e.g., mp4,mkv,mp3). |
| action    | String  | (none)  | Triggers specific backend actions. Pass ytdlp to use the yt-dlp API endpoint.                                  |
| format    | String  | txt     | Output format for the Playlist API. Supported formats: txt, m3u, m3u8, xspf, json.                             |
| shuffle   | Boolean | false   | Pass 1, true, or yes to randomize the order of the files in the output.                                        |
| search    | String  | (empty) | Filter the results by a specific search keyword.                                                               |

### Examples

1. Generate an M3U playlist of all MP4 and MKV files:

```
https://yourdomain.com/media/?ext=mp4,mkv&format=m3u
```

1. Get a plain text list of all MP3 files, shuffled:

```
https://yourdomain.com/media/?ext=mp3&shuffle=true
```

1. Generate a playlist of MP4 files containing the word "Matrix":

```
https://yourdomain.com/media/?ext=mp4&search=matrix&format=m3u
```

## 📥 yt-dlp Integration

The script includes an API endpoint to download external media (like YouTube videos) directly to your server using yt-dlp.

### Configuration

For security, this endpoint is disabled by default. To enable it, you must configure a secret key by setting the PHP_SECRET_KEY environment variable on your web server.

Apache (.htaccess or VirtualHost):

```
SetEnv PHP_SECRET_KEY "your_secure_password"
```

Nginx (fastcgi_params):

```
fastcgi_param PHP_SECRET_KEY "your_secure_password";
```

Docker / PHP-FPM:

Set the variable in your docker-compose.yml or PHP pool config (env\[PHP_SECRET_KEY\] = "your_secure_password"), or pass it using -e in the docker run command as shown in the Docker Deployment section.

Once configured, the web player will detect the secret and unlock the yt-dlp download capabilities via ?action=ytdlp.

## 💾 Local Storage

The Web Player utilizes your browser's localStorage to remember:

- Volume settings
- Loop preferences
- Playback history (Your 50 most recently played files)

## ⚙️ Requirements

- A web server running PHP 7.4 or higher.
- An internet connection (for loading Tailwind CSS and FontAwesome icons via CDN in the Web Player).
- (Optional) yt-dlp installed and accessible in your server's PATH to use the download integration.

## 📝 License

This project is open-source and available under the CC0 License. Feel free to modify and distribute as needed.
