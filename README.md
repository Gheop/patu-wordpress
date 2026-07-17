# 🕷️ Patu for WordPress

Shrink your media library through the [Patu](https://patu.dev) optimization API,
straight from wp-admin. New uploads are optimized automatically, your existing
library in one bulk pass. Same URLs, same quality, never bigger, never breaks
your site.

## What it does

WordPress makes several sized copies of every image you upload (thumbnail,
medium, large, full). Patu re-encodes each of them through the Patu API and
writes back the smaller version **in place**, keeping the same format and
dimensions. Nothing about the URL changes, so it works with every theme and
every page builder.

- 🧵 **Automatic on upload.** New images are optimized as they arrive.
- 🕸️ **Bulk optimize** your whole existing library from one screen, with a live
  progress bar.
- 🪶 **Never bigger.** A file is only replaced when the optimized version is
  strictly smaller.
- 🧶 **Never breaks your site.** A failed optimization is skipped, never fatal,
  and the write is atomic. Your uploads never hang and your images never end up
  half-written.
- ↩️ **One-click restore.** Originals are backed up, so any image can be put
  back exactly as it was.
- 🔒 **Private.** Your API key is only ever sent to patu.dev in a request header.

## Setup

1. Install and activate the plugin.
2. Open **Patu → Settings**, paste a free key from [patu.dev](https://patu.dev),
   and hit **Test connection**.
3. That's it. New uploads optimize themselves. For everything already in your
   library, open **Patu → Bulk Optimize** and click **Optimize all**.

Prefer to keep the key out of the database? Put it in `wp-config.php`:

```php
define( 'PATU_API_KEY', 'your_key_here' );
```

## Two modes

Pick one in **Patu → Settings**:

- **Optimize in place** (default). Re-encodes JPEG and WebP images to a smaller
  version of the same format, written back over the file. URLs never change, so
  it works with every theme and page builder. JPEG is the bulk of most
  libraries' weight, and this saves roughly a quarter of its size with no
  visible quality change.
- **Serve next-gen formats** (AVIF/WebP). Generates modern versions of your
  images (JPEG, PNG and WebP) next to the originals and serves them through a
  `<picture>` tag, mapping the responsive `srcset` and keeping the original as
  the fallback for older browsers. The biggest savings, theme-independent
  (it rewrites the final HTML, so page builders are covered too), and it handles
  PNG.

Known limitations: GIF is not covered yet, CSS `background-image`s aren't
rewritten (only `<img>`), and JavaScript-driven lazy-loaders that swap `src` at
runtime may not get the next-gen version. Native `loading="lazy"` is fine.

## External service & privacy

The compression itself runs on the Patu API, so the plugin sends your images
there. When you optimize an image (on upload, or via a bulk run) it uploads the
image bytes and your API key over HTTPS to [patu.dev](https://patu.dev) and gets
the optimized bytes back. Only the image is sent — none of your site's content,
users, or other data. By using it you agree to the Patu
[terms](https://patu.dev/terms) and [privacy policy](https://patu.dev/privacy).

## For developers

The plugin is plain, dependency-free PHP built on WordPress's own APIs. A few
filters let you tune it:

- `patu_resolved_key` — override the API key at runtime.
- `patu_endpoint` — point at a self-hosted or staging Patu endpoint.
- `patu_timeout` — the per-request timeout (default 30s).
- `patu_backup` — force backups on or off.

A local test setup (WordPress + MariaDB + WP-CLI via docker-compose) and the
integration test scripts live in this repo.

## About the name

*Patu digua* is one of the smallest spiders known, about 0.37 mm, small enough to
live on the webs of larger spiders. It weaves the lightest thread there is. 🕸️

## License

[GPLv2 or later](./LICENSE), matching the WordPress ecosystem.
