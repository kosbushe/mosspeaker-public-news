# Moscow Speaker public news

Public headline feed for https://mosspeaker.ru/.

Sources: https://t.me/s/mos_speaker and https://t.me/s/gorkyparkmsk.
Only short headlines, source timestamps and links are collected. No Telegram account, bot token, cookies, user submissions or private data are used.

GitHub Actions checks the sources twice an hour. Scheduled runs may be delayed by GitHub. Posts older than seven days are excluded. On failure the previous dated feed is retained. The website also shows the original publication date and cached-feed status.

## Data path

Sites `/api/public-news` reads the two public channels. GitHub Actions requests it at minutes 25 and 55 (UTC) and stores `feed.json`. If Sites is unavailable, the existing direct parser is used. The existing SpaceWeb cron refreshes the website cache every five minutes. A failed refresh retains the previous dated feed. GitHub schedules can be delayed.

Each workflow waits for the next SpaceWeb cron boundary and reads the live endpoint without priming it first. It checks both sources, feed timestamp and the native cron timestamp, then verifies the locked video introduction and availability of its video. No page content or DNS is modified by this verification.
