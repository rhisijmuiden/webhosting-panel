"""Visitors, page views and requests per website, per hour and per day.

Read from the same Nginx traffic log as the data traffic (actions/traffic.py),
in the extended line format:

    <server_name> <bytes_sent> <request_length> <msec> <status> <remote_addr> "<content type>" "<user agent>"

- request:  every line (static files included)
- bot:      a request whose user agent looks like a crawler, monitor or script
- page view: a 2xx/304 response with an HTML content type, not from a bot
- visitor:  a distinct (IP address + user agent) with at least one page view,
            counted per hour and per day. Only a salted hash is kept, the salt
            changes every day, and the hashes are thrown away after two days:
            no IP address is ever stored.

The key "*" holds the whole server, where a visitor of two websites counts once.
"""

import hashlib
import os
import re
import time

LINE = re.compile(r'^([A-Za-z0-9.-]{1,253}) (\d+) (\d+) (\d+)(?:\.\d+)? (\d{3}) (\S+) "([^"]*)" "([^"]*)"$')
BOT = re.compile(
    r"bot|crawl|spider|slurp|scrap|curl|wget|python|go-http|java/|okhttp|libwww|httpclient|axios|node-fetch"
    r"|monitor|uptime|pingdom|statuscake|headless|lighthouse|preview|facebookexternalhit|whatsapp|embedly"
    r"|feedfetcher|rss|validator|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|wordpress/",
    re.I,
)
FIELDS = ("requests", "pageviews", "bots", "bytes", "visitors")
SERVER = "*"
KEEP_HOURS = 72
KEEP_DAYS = 8
MAX_SET = 250_000


def _day(ts):
    return time.strftime("%Y-%m-%d", time.localtime(ts))


def _day_start(ts):
    t = time.localtime(ts)
    return int(time.mktime((t.tm_year, t.tm_mon, t.tm_mday, 0, 0, 0, 0, 0, -1)))


class Visits:
    """Adds parsed log lines to the "visits" part of the traffic state."""

    def __init__(self, state):
        self.state = state
        state.setdefault("hours", {})
        state.setdefault("days", {})
        state.setdefault("salts", {})
        state.setdefault("sets", {})
        self._sets = {}

    def _salt(self, day):
        salts = self.state["salts"]
        if day not in salts:
            salts[day] = os.urandom(16).hex()
        return salts[day]

    def _set(self, key, site):
        bucket = self._sets.setdefault(key, {})
        if site not in bucket:
            bucket[site] = set(self.state["sets"].get(key, {}).get(site, []))
        return bucket[site]

    @staticmethod
    def _counter(bucket, site):
        row = bucket.get(site)
        if row is None:
            row = bucket[site] = {f: 0 for f in FIELDS}
        return row

    def add_line(self, line):
        """Count one log line. Returns (name, bytes) for the monthly traffic, or None."""
        m = LINE.match(line)
        if not m:
            return None
        name, sent, received, ts, status, addr, ctype, agent = m.groups()
        name = name.lower()
        size = int(sent) + int(received)
        ts = int(ts)
        status = int(status)
        bot = bool(BOT.search(agent)) or agent in ("", "-")
        page = not bot and (200 <= status < 300 or status == 304) and ctype.lower().startswith(("text/html", "application/xhtml"))

        hour = str(ts // 3600 * 3600)
        day = _day(ts)
        hours = self.state["hours"].setdefault(hour, {})
        days = self.state["days"].setdefault(day, {"start": _day_start(ts), "sites": {}})["sites"]
        visitor = None
        if page:
            visitor = hashlib.blake2b((self._salt(day) + addr + "|" + agent).encode("utf-8", "replace"), digest_size=8).hexdigest()
        for site in (name, SERVER):
            for bucket, key in ((hours, "h:" + hour), (days, "d:" + day)):
                row = self._counter(bucket, site)
                row["requests"] += 1
                row["bytes"] += size
                if bot:
                    row["bots"] += 1
                if page:
                    row["pageviews"] += 1
                    seen = self._set(key, site)
                    if visitor not in seen and len(seen) < MAX_SET:
                        seen.add(visitor)
                        row["visitors"] += 1
        return name, size

    def finish(self, now=None):
        """Write the visitor sets back and drop what is no longer needed."""
        now = int(now if now is not None else time.time())
        sets = self.state["sets"]
        for key, sites in self._sets.items():
            sets[key] = {site: sorted(seen) for site, seen in sites.items()}

        oldest_hour = now // 3600 * 3600 - KEEP_HOURS * 3600
        for hour in [h for h in self.state["hours"] if int(h) < oldest_hour]:
            del self.state["hours"][hour]
        days = sorted(self.state["days"])
        for day in days[:-KEEP_DAYS]:
            del self.state["days"][day]
        # Late lines (Nginx buffers up to a minute) still find the visitors of the
        # previous hour and day; older hashes and salts are removed.
        keep_sets = {"h:" + str(now // 3600 * 3600 - i * 3600) for i in range(3)}
        keep_days = {_day(now), _day(now - 86400)}
        keep_sets |= {"d:" + d for d in keep_days}
        for key in [k for k in sets if k not in keep_sets]:
            del sets[key]
        for day in [d for d in self.state["salts"] if d not in keep_days]:
            del self.state["salts"][day]


def history(state, since=0):
    """Hour and day buckets that start at or after since (epoch seconds)."""
    hours = [{"start": int(h), "sites": sites} for h, sites in sorted(state.get("hours", {}).items(), key=lambda kv: int(kv[0])) if int(h) >= since]
    days = [{"day": d, "start": v["start"], "sites": v["sites"]} for d, v in sorted(state.get("days", {}).items())
            if v["start"] + 86400 > since]
    return {"hours": hours, "days": days}
