<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
?>
{
  "name": "GEMB Admin — Estate Management",
  "short_name": "GEMB Admin",
  "description": "Administration portal for Mossel Bay Golf Estate",
  "start_url": "/admin.php?action=login",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#c0392b",
  "theme_color": "#c0392b",
  "icons": [
    {
      "src": "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'><rect width='192' height='192' rx='24' fill='%23c0392b'/><text y='130' x='96' text-anchor='middle' font-size='110'>⚙️</text></svg>",
      "sizes": "192x192",
      "type": "image/svg+xml",
      "purpose": "any maskable"
    },
    {
      "src": "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'><rect width='512' height='512' rx='64' fill='%23c0392b'/><text y='350' x='256' text-anchor='middle' font-size='300'>⚙️</text></svg>",
      "sizes": "512x512",
      "type": "image/svg+xml",
      "purpose": "any maskable"
    }
  ],
  "categories": ["utilities"],
  "lang": "en"
}
