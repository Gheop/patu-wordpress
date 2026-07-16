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

## What v1 covers

v1 optimizes **JPEG and WebP** images in place, same format. JPEG is the bulk of
most libraries' weight, and re-encoding it saves roughly a quarter of its size
with no visible quality change.

**Coming next:** PNG and GIF, and WebP/AVIF delivery (serving next-gen formats to
browsers that support them). These need format conversion and delivery handling
that has to be rock-solid across themes, so they get their own release rather
than a rushed one here.

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

[MIT](./LICENSE).
