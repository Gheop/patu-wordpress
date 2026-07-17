=== Patu Optimizer ===
Contributors: gheop
Tags: image optimization, compress images, optimize images, webp, performance
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optimize your media library's JPEG and WebP images through the Patu API. Smaller files, same quality, never bigger, never broken.

== Description ==

Patu shrinks the images in your WordPress media library by re-encoding them
through the [Patu](https://patu.dev) optimization API. It works in place and
keeps the same format and dimensions, so your URLs never change and it works
with every theme and page builder.

* **Automatic** — new uploads are optimized as they come in, across every
  generated size (thumbnail, medium, large, full).
* **Bulk** — optimize your entire existing library from one screen, with a live
  progress bar.
* **Safe** — a file is only replaced when the optimized version is strictly
  smaller (never bigger), a failed optimization never blocks an upload or breaks
  your site (never broken), and originals are backed up so you can restore any
  image with one click.
* **Private** — your API key is only ever sent to patu.dev in a request header.

Get a free API key at [patu.dev](https://patu.dev).

**Two modes:**

* **Optimize in place** (default): re-encodes JPEG and WebP images to a smaller
  version of the same format. URLs never change, so it works with every theme
  and page builder. JPEG is the bulk of most libraries' weight, and this saves
  roughly a quarter of its size with no visible quality change.
* **Serve next-gen formats** (AVIF/WebP): generates modern versions of your
  images (JPEG, PNG and WebP) and serves them through a `<picture>` tag, keeping
  the original as a fallback for older browsers. The biggest savings, and it
  covers PNG too.

== External service ==

This plugin relies on the Patu image-optimization API to do the actual
compression. It is the core function of the plugin and it cannot work without
it.

- **What is sent, and when:** the image files you optimize (on upload if
  auto-optimize is on, or when you run a bulk optimize / next-gen generate),
  plus your API key in the `X-Api-Key` header. A small built-in sample image is
  also sent when you click "Test connection". Everything goes over HTTPS to
  `https://patu.dev`.
- **What comes back:** the optimized image bytes. Nothing else about your site
  (its content, users, or any personal data) is sent — only the image itself.

The service is operated by Patu (https://patu.dev). By using it you agree to its
terms and privacy policy:

- Terms of service: https://patu.dev/terms
- Privacy policy: https://patu.dev/privacy

== Installation ==

1. Upload the `patu-optimizer` folder to `/wp-content/plugins/`, or install the
   plugin through the Plugins screen.
2. Activate it through the Plugins screen.
3. Go to **Patu → Settings**, paste your API key from
   [patu.dev](https://patu.dev), and click **Test connection**.
4. New uploads are optimized automatically. To optimize existing images, open
   **Patu → Bulk Optimize** and click **Optimize all**.

You can also set the key in `wp-config.php` instead of the database:

    define( 'PATU_API_KEY', 'your_key_here' );

== Frequently Asked Questions ==

= Does it change my image URLs? =

No. v1 optimizes JPEG and WebP in place, keeping the same file, format and
dimensions, so every URL stays exactly as it was.

= Can I undo it? =

Yes. Keep "Keep originals" on (the default) and every optimized image can be
restored to its exact original from the media library or the Bulk Optimize
screen.

= Will it ever make a file bigger or break an image? =

No. A file is only replaced when the optimized version is strictly smaller, the
write is atomic, and any API or network error is skipped without touching the
original or interrupting your upload.

= What about PNG, and AVIF/WebP delivery? =

Switch to the "Serve next-gen formats" mode. It generates AVIF (and WebP)
versions of your JPEG, PNG and WebP images and serves them through a `<picture>`
tag, with the original kept as a fallback. GIF is not covered yet.

= Where does my API key go? =

Only to patu.dev, and only in the `X-Api-Key` request header. It is never
logged; on the settings page only an administrator can see it, in a masked
password field.

== Changelog ==

= 0.2.0 =
* New "Serve next-gen formats" mode: generates AVIF (and WebP) versions of your images and serves them through a <picture> tag, keeping the original as a fallback. Covers PNG too. The in-place mode stays the default.
* All file operations now go through WP_Filesystem.
* Licensed GPLv2 or later.

= 0.1.0 =
* First release: in-place JPEG and WebP optimization, optimize-on-upload, bulk
  optimize with progress, per-image and bulk restore, media library column,
  settings with a connection test, and stats.
