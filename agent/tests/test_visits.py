import os
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS  # noqa: E402

load_all()

from whp_agent.visits import _day_start  # noqa: E402

T = _day_start(time.time()) - 86400 + 10 * 3600  # yesterday 10:00, so hours do not cross midnight
UA = "Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0"


def line(site, ts, status=200, addr="203.0.113.5", ctype="text/html; charset=UTF-8", ua=UA, sent=1000, recv=100):
    return f'{site} {sent} {recv} {ts}.123 {status} {addr} "{ctype}" "{ua}"\n'


class VisitsTest(unittest.TestCase):
    def setUp(self):
        from whp_agent import paths
        self.tmp = tempfile.TemporaryDirectory()
        self.patch = mock.patch.object(paths, "STATE_DIR", self.tmp.name)
        self.patch.start()
        self.log = os.path.join(self.tmp.name, "whp-traffic.log")

    def tearDown(self):
        self.patch.stop()
        self.tmp.cleanup()

    def write(self, *lines, mode="a"):
        with open(self.log, mode) as fh:
            fh.write("".join(lines))

    def test_registered(self):
        self.assertIn("traffic.history", ACTIONS)

    def test_counts_visitors_pageviews_bots_and_requests(self):
        from whp_agent.actions import traffic
        self.write(
            line("Shop.nl", T + 10),
            line("shop.nl", T + 20),                                  # same visitor, 2nd page view
            line("shop.nl", T + 30, ctype="image/webp"),              # asset: request, no page view
            line("shop.nl", T + 40, addr="198.51.100.7"),             # 2nd visitor
            line("shop.nl", T + 50, ua="Googlebot/2.1"),              # bot
            line("shop.nl", T + 60, status=404),                      # not a page view
            line("blog.nl", T + 70),                                  # same person on another site
            line("shop.nl", T + 3600 + 5),                            # next hour: visitor again
            "old.nl 10 10\n",                                         # old format: bytes only
        )
        state = traffic.collect(self.log, now=T + 3700)
        month = time.strftime("%Y-%m", time.gmtime(T + 3700))
        self.assertEqual(state["months"][month]["shop.nl"], 7 * 1100)
        self.assertEqual(state["months"][month]["old.nl"], 20)

        hours = state["visits"]["hours"]
        shop = hours[str(T)]["shop.nl"]
        self.assertEqual(shop, {"requests": 6, "pageviews": 3, "bots": 1, "bytes": 6600, "visitors": 2})
        self.assertEqual(hours[str(T + 3600)]["shop.nl"]["visitors"], 1)
        self.assertEqual(hours[str(T)]["*"]["visitors"], 2, "the server counts a person on two sites once")
        self.assertNotIn("old.nl", hours[str(T)])

        day = next(iter(state["visits"]["days"].values()))["sites"]
        self.assertEqual(day["shop.nl"]["visitors"], 2, "a visitor in two hours counts once per day")
        self.assertEqual(day["shop.nl"]["pageviews"], 4)

        # No IP address ends up in the state file.
        with open(os.path.join(self.tmp.name, "traffic.json")) as fh:
            self.assertNotIn("203.0.113.5", fh.read())

        # Reading again does not count twice; a later page view by a known visitor is not new.
        self.write(line("shop.nl", T + 3600 + 50))
        state = traffic.collect(self.log, now=T + 3700)
        self.assertEqual(state["visits"]["hours"][str(T + 3600)]["shop.nl"], {"requests": 2, "pageviews": 2, "bots": 0, "bytes": 2200, "visitors": 1})

    def test_history_and_pruning(self):
        from whp_agent.actions import traffic
        self.write(line("a.nl", T), line("a.nl", T + 7200))
        traffic.collect(self.log, now=T + 7300)
        with mock.patch.object(traffic, "LOG_FILE", self.log):
            out = ACTIONS["traffic.history"]({"since": T + 3600})
        self.assertEqual([h["start"] for h in out["hours"]], [T + 7200])
        self.assertTrue(out["days"])

        # Three days later the hours are gone, and so are the visitor hashes.
        state = traffic.collect(self.log, now=T + 4 * 86400)
        self.assertEqual(state["visits"]["hours"], {})
        self.assertEqual(state["visits"]["sets"], {})
        self.assertEqual(state["visits"]["salts"], {})


if __name__ == "__main__":
    unittest.main()
