<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
?>
{
  "name": "GEMB Communications Portal",
  "short_name": "GEMB Comms",
  "description": "Estate Communications Portal for GEMB",
  "start_url": "/comms/comms_login.php",
  "scope": "/comms/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#0D47A1",
  "theme_color": "#0D47A1",
  "icons": [
    {
      "src": "icon-192-comms.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "icon-512-comms.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ],
  "categories": ["business", "utilities"],
  "lang": "en"
}
