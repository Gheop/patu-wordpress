# WordPress.org directory assets

These are the assets for the plugin's page on wordpress.org — **not** part of the
plugin itself. They are never bundled in `patu.zip`; after the plugin is
approved they go into the SVN **`/assets/`** directory (a sibling of `/trunk/`),
not into `/trunk/`.

| File | Purpose |
|------|---------|
| `icon.svg` | Plugin icon (vector, preferred) |
| `icon-128x128.png` | Plugin icon fallback |
| `icon-256x256.png` | Plugin icon, retina |
| `banner-772x250.png` | Header banner |
| `banner-1544x500.png` | Header banner, retina |

The PNGs are rendered from `icon.svg` / `banner.svg` with `resvg` and optimized
losslessly with `optipng`. To regenerate:

    resvg --width 128  --height 128 icon.svg   icon-128x128.png
    resvg --width 256  --height 256 icon.svg   icon-256x256.png
    resvg --width 772  --height 250 banner.svg banner-772x250.png
    resvg --width 1544 --height 500 banner.svg banner-1544x500.png
    optipng -o5 *.png
