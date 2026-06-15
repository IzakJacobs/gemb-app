<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
?>
{
  "name": "MBGE Guard — Gate Access",
  "short_name": "MBGE Guard",
  "description": "Gate verification for MBGE security guards",
  "start_url": "/guard.php?action=login",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#1a6b3c",
  "theme_color": "#1a6b3c",
  "icons": [
    {
      "src": "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'><rect width='192' height='192' rx='24' fill='%231a6b3c'/><text y='130' x='96' text-anchor='middle' font-size='110'>🔐</text></svg>",
      "sizes": "192x192",
      "type": "image/svg+xml",
      "purpose": "any maskable"
    },
    {
      "src": "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'><rect width='512' height='512' rx='64' fill='%231a6b3c'/><text y='350' x='256' text-anchor='middle' font-size='300'>🔐</text></svg>",
      "sizes": "512x512",
      "type": "image/svg+xml",
      "purpose": "any maskable"
    }
  ],
  "categories": ["security", "utilities"],
  "lang": "en"
}
