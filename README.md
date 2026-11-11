- [English](README.md)
- [简体中文](README.zh-CN.md)

# NCDownloader

An easy-to-use web interface for Aria2 and yt-dlp in Nextcloud (compatible with **Nextcloud 28 - 35** and **PHP 8.1 - 8.5**).

- Control Aria2 and manage HTTP(S), FTP(S), Magnet and BitTorrent downloads from the web interface
- Download videos and audio tracks from hundreds of platforms via **yt-dlp** (with fallback to `youtube-dl`)
- Automatic Nextcloud Files indexing as soon as downloads complete (no manual `occ files:scan` required)
- Built-in torrent search across supported providers

<img width="800" alt="nc2" src="https://user-images.githubusercontent.com/3911975/132008308-dec2a7ba-4387-441e-9ded-538d61fbccf0.png">
<img width="800" alt="nc4" src="https://user-images.githubusercontent.com/3911975/142444998-54dd54a6-0c8e-4d49-8188-270964a99c50.png">
<img width="800" alt="nc5" src="https://user-images.githubusercontent.com/3911975/142445020-27ec389a-5437-4d28-acc0-5e757fd6897d.png">

### Requirements and installation

Install `aria2` and `yt-dlp` on the Nextcloud host (or container):

```bash
sudo apt-get update && sudo apt-get install -y aria2 ffmpeg python3
sudo curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod 0755 /usr/local/bin/yt-dlp
```

You can also configure a custom binary path in **Administration settings -> NCDownloader**.

### How to build front-end code

Node.js 20+ and npm 10+ are recommended to build the front-end bundles:

```bash
npm install --legacy-peer-deps
npm run build
```

### Repository

https://github.com/eldertek/ncdownloader
