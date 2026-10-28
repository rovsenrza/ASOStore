# MTProxy (Telegram for RU users without VPN)

mtg 2.2.8 fake-TLS proxy, hidden behind the site on :443.

    client :443 -> nginx stream (ops/nginx/stream-sni-443.conf, ssl_preread)
        SNI www.ruappstore.com -> mtg 127.0.0.1:8445 (PROXY protocol)
            Telegram client     -> Telegram DCs
            anything else       -> fronted to the site 127.0.0.1:8444 (PROXY protocol)
        any other SNI           -> site 127.0.0.1:8444 (PROXY protocol)

Probes see the real site and a valid cert; real client IPs reach PHP via
`real_ip_header proxy_protocol` (ops/nginx/real-ip-stream.conf).

Install on prod:
- `apt install libnginx-mod-stream`; add `stream { include /etc/nginx/stream.d/*.conf; }` to nginx.conf
- `/etc/mtg/config.toml` from ops/mtg/config.toml (real secret lives only on the server)
- `ops/systemd/mtg.service` -> `systemctl enable --now mtg`
- check: `/opt/mtg/mtg doctor /etc/mtg/config.toml`

User link (port is 443, not mtg's internal 8445):
`https://t.me/proxy?server=www.ruappstore.com&port=443&secret=<base64 secret from 'mtg access'>`
