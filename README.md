# Single-File Web Media Player & API

A lightweight, single-file PHP script that instantly turns any directory of media files into a fully-featured, responsive Web Media Player and an automated M3U playlist generator.

Just drop index.php into a folder containing your videos or music, and it will automatically scan, categorize, and serve them.

## 🚀 Features

- Zero Configuration: No database required. Drop the script into a directory, and it works instantly.
- Modern Web Player: A beautiful, responsive UI built with Tailwind CSS. Includes custom controls for video and audio playback.
- Smart Subtitles: Automatically detects and loads sidecar .vtt and .srt subtitles, complete with language tag recognition (e.g., movie.en.vtt).
- Dynamic Media Library: \* Auto-generated category tabs based on available extensions (e.g., .mp4, .mp3, .mkv).
  - Live search filtering.
  - "Recent" tab to resume previously played media.
  - Background reloading (update the list without refreshing the page).
- Playback Controls: Shuffle, Random, Loop (All/Current), and Play All.
- M3U / API Generator: Generate dynamic playlists for external players (like VLC) via simple URL parameters.
- Cloudflare Safe: Built-in reverse proxy detection for accurate URL generation.

## 🛠️ Installation & Usage

1. Download the index.php file.
2. Place it in a directory on your web server that contains media files (e.g., /var/www/html/movies/).
3. Open the directory in your web browser (e.g., https://yourdomain.com/movies/).

That's it! The Web Player will load automatically.

## 🎬 Subtitle Support

The player automatically loads subtitles if they share the same base filename as the video. It supports .vtt and .srt formats.

Naming conventions: If your video is named MyMovie.mp4, the script will automatically attach:

- MyMovie.vtt (Displays as "VTT")
- MyMovie.srt (Displays as "SRT")
- MyMovie.en.vtt (Extracts language tag, displays as "EN")
- MyMovie.pt-BR.srt (Extracts language tag, displays as "PT-BR")

Subtitles can be toggled via the \[CC\] button in the player controls.

## 🔌 API & M3U Playlist Generator

You can bypass the Web Player and generate raw text lists or .m3u playlists for external media players (like VLC, Apple TV, etc.) by passing URL parameters.

### Parameters

| Parameter | Type    | Default | Description                                                                                                |
|-----------|---------|---------|------------------------------------------------------------------------------------------------------------|
| ext       | String  | (none)  | Required to trigger API mode. Comma-separated list of file extensions to include (e.g., mp4,mkv,mp3).      |
| format    | String  | txt     | Output format. Format of downloadable playlist file (supported txt, m3u, m3u8, xspf).                      |
| shuffle   | Boolean | false   | Pass 1, true, or yes to randomize the order of the files in the output.                                    |
| search    | String  | (empty) | Filter the results by a specific search keyword.                                                           |

### Examples

1. Generate an M3U playlist of all MP4 and MKV files:

```
https://yourdomain.com/media/?ext=mp4,mkv&format=m3u
```

2. Get a plain text list of all MP3 files, shuffled:

```
https://yourdomain.com/media/?ext=mp3&shuffle=true
```

3. Generate a playlist of MP4 files containing the word "Matrix":

```
https://yourdomain.com/media/?ext=mp4&search=matrix&format=m3u
```

## 💾 Local Storage

The Web Player utilizes your browser's localStorage to remember:

- Volume settings
- Loop preferences
- Playback history (Your 50 most recently played files)

## ⚙️ Requirements

- A web server running PHP 7.4 or higher.
- An internet connection (for loading Tailwind CSS and FontAwesome icons via CDN in the Web Player).

## 📝 License

This project is open-source and available under the CC0 License. Feel free to modify and distribute as needed.
