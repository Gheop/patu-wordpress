=== Patu ===
Contributors: patu
Tags: image optimization, compress images, optimize images, webp, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

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

**What v1 optimizes:** JPEG and WebP images, in place, same format. JPEG is the
bulk of most media libraries' weight, and re-encoding it saves roughly a quarter
of its size with no visible quality change.

**Coming next:** PNG and GIF, and WebP/AVIF delivery (serving next-gen formats to
browsers that support them).

== Installation ==

1. Upload the `patu` folder to `/wp-content/plugins/`, or install the plugin
   through the Plugins screen.
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

= What about PNG, GIF, and WebP/AVIF delivery? =

Those are planned for a future version. v1 focuses on JPEG and WebP because they
can be optimized in place without changing the file's format or URL.

= Where does my API key go? =

Only to patu.dev, and only in the `X-Api-Key` request header. It is never
logged; on the settings page only an administrator can see it, in a masked
password field.

== Changelog ==

= 0.1.0 =
* First release: in-place JPEG and WebP optimization, optimize-on-upload, bulk
  optimize with progress, per-image and bulk restore, media library column,
  settings with a connection test, and stats.
