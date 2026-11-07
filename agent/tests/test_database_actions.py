"""Client database management: databases, extra users, passwords and phpMyAdmin sign-on."""

import json
import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from whp_agent import paths  # noqa: E402
from whp_agent.actions import databases as db  # noqa: E402
from whp_agent.actions import load_all  # noqa: E402
from whp_agent.registry import ACTIONS, ActionError  # noqa: E402

load_all()


class DatabaseActionsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.sso_dir = os.path.join(self.tmp.name, "pma")
        self.sql = []
        self.existing = set()
        patches = [
            mock.patch.object(paths, "STATE_DIR", self.tmp.name),
            mock.patch.object(db, "SSO_DIR", self.sso_dir),
            mock.patch.object(db, "sql", side_effect=self.fake_sql),
            mock.patch.object(db, "run"),
            mock.patch.object(db, "_sso_owner", return_value=(os.getuid(), os.getgid())),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)
        self.addCleanup(self.tmp.cleanup)

    def fake_sql(self, statements, timeout=60):
        self.sql.append(statements)
        if statements.startswith("SELECT COUNT(*)"):
            return "1\n" if any(f"'{n}'" in statements for n in self.existing) else "0\n"
        return ""

    def all_sql(self):
        return "".join(self.sql)

    def test_registered(self):
        for name in ["db.list", "db.create", "db.drop", "db.adopt", "db.user_create", "db.user_update",
                     "db.user_drop", "db.password", "db.sso", "db.sso_cleanup", "db.limits"]:
            self.assertIn(name, ACTIONS)

    def test_create_with_own_name_and_password(self):
        out = db.db_create({"domain": "bakkerij.nl", "name": "shop", "password": "Een-lang wachtwoord'\\x"})
        self.assertEqual(out["name"], "bakkerij_nl_shop")
        self.assertEqual(out["password"], "Een-lang wachtwoord'\\x")
        # The quote and backslash are escaped inside the literal.
        self.assertIn("IDENTIFIED BY 'Een-lang wachtwoord\\'\\\\x'", self.all_sql())
        self.assertIn("GRANT ALL PRIVILEGES ON `bakkerij_nl_shop`.* TO 'bakkerij_nl_shop'@'localhost'", self.all_sql())
        listed = db.db_list({"domain": "bakkerij.nl"})
        self.assertEqual(listed["prefix"], "bakkerij_nl_")
        self.assertEqual([d["name"] for d in listed["databases"]], ["bakkerij_nl_shop"])

    def test_create_refuses_bad_names_and_taken_names(self):
        for bad in ["Shop", "../x", "a" * 40, "_x", "x;drop"]:
            with self.assertRaises(ActionError):
                db.db_create({"domain": "bakkerij.nl", "name": bad})
        with self.assertRaises(ActionError):
            db.db_create({"domain": "bakkerij.nl", "name": "shop", "password": "short"})
        self.existing.add("bakkerij_nl_shop")
        with self.assertRaises(ActionError) as e:
            db.db_create({"domain": "bakkerij.nl", "name": "shop"})
        self.assertEqual(e.exception.code, "conflict")

    def test_generated_name_and_password(self):
        out = db.db_create({"domain": "a.nl"})
        self.assertTrue(out["name"].startswith("a_nl_db_"))
        self.assertEqual(len(out["password"]), 32)

    def test_extra_users(self):
        one = db.db_create({"domain": "a.nl", "name": "one"})["name"]
        two = db.db_create({"domain": "a.nl", "name": "two"})["name"]
        other = db.db_create({"domain": "b.nl", "name": "x"})["name"]
        with self.assertRaises(ActionError):  # a database of another site
            db.db_user_create({"domain": "a.nl", "name": "rep", "databases": [one, other]})
        out = db.db_user_create({"domain": "a.nl", "name": "rep", "databases": [one, two], "readonly": True})
        self.assertEqual(out["user"], "a_nl_rep")
        self.assertIn("GRANT SELECT, SHOW VIEW ON `a_nl_one`.* TO 'a_nl_rep'@'localhost'", self.all_sql())
        listed = db.db_list({"domain": "a.nl"})
        self.assertEqual([u["user"] for u in listed["users"]], ["a_nl_rep"])
        first = [d for d in listed["databases"] if d["name"] == one][0]
        self.assertEqual([u["user"] for u in first["users"]], ["a_nl_one", "a_nl_rep"])

        db.db_user_update({"domain": "a.nl", "user": "a_nl_rep", "databases": [two], "readonly": False})
        self.assertIn("GRANT ALL PRIVILEGES ON `a_nl_two`.* TO 'a_nl_rep'@'localhost'", self.sql[-1])
        self.assertEqual(db._load_users()["a_nl_rep"]["databases"], [two])
        with self.assertRaises(ActionError):  # main users keep full rights
            db.db_user_update({"domain": "a.nl", "user": "a_nl_one", "databases": [one]})
        with self.assertRaises(ActionError):  # not a user of b.nl
            db.db_user_drop({"domain": "b.nl", "user": "a_nl_rep"})

        # Dropping the last database of an extra user drops the user too.
        out = db.drop(two, "a.nl")
        self.assertEqual(out["dropped_users"], ["a_nl_rep"])
        self.assertNotIn("a_nl_rep", db._load_users())

    def test_password_reset_only_for_own_users(self):
        name = db.db_create({"domain": "a.nl", "name": "one"})["name"]
        out = db.db_password({"domain": "a.nl", "user": name})
        self.assertEqual(len(out["password"]), 32)
        self.assertIn(f"ALTER USER '{name}'@'localhost' IDENTIFIED BY '{out['password']}'", self.sql[-1])
        with self.assertRaises(ActionError):
            db.db_password({"domain": "b.nl", "user": name})
        with self.assertRaises(ActionError):
            db.db_password({"domain": "a.nl", "user": "root"})

    def test_adopt_migrated_database(self):
        self.existing |= {"oldshop", "olduser"}
        out = db.db_adopt({"domain": "a.nl", "name": "oldshop", "user": "olduser"})
        self.assertTrue(out["adopted"])
        self.assertNotIn("CREATE USER", self.all_sql())  # the user exists: its password is kept
        self.assertEqual(db.db_list({"domain": "a.nl"})["databases"][0]["user"], "olduser")
        self.assertFalse(db.db_adopt({"domain": "a.nl", "name": "oldshop", "user": "olduser"})["adopted"])
        with self.assertRaises(ActionError):
            db.db_adopt({"domain": "b.nl", "name": "oldshop"})
        for bad in ("mysql", "whp_panel"):
            with self.assertRaises(ActionError):
                db.db_adopt({"domain": "a.nl", "name": bad})
        with self.assertRaises(ActionError):
            db.db_adopt({"domain": "a.nl", "name": "missing"})

    def test_sso_token_and_cleanup(self):
        name = db.db_create({"domain": "a.nl", "name": "one"})["name"]
        with self.assertRaises(ActionError):
            db.db_sso({"domain": "b.nl", "name": name})
        out = db.db_sso({"domain": "a.nl", "name": name, "lang": "nl"})
        self.assertRegex(out["token"], r"^[a-f0-9]{64}$")
        with open(os.path.join(self.sso_dir, out["token"] + ".json")) as fh:
            login = json.load(fh)
        self.assertRegex(login["user"], r"^whp_pma_[a-f0-9]{12}$")
        self.assertEqual((login["database"], login["lang"]), (name, "nl"))
        self.assertEqual(oct(os.stat(os.path.join(self.sso_dir, out["token"] + ".json")).st_mode & 0o777), "0o600")
        self.assertIn(f"GRANT ALL PRIVILEGES ON `{name}`.* TO '{login['user']}'@'localhost'", self.all_sql())
        self.assertNotIn("password", out)

        # Nothing expired yet; later, the user and the unused token are removed.
        self.assertEqual(db.db_sso_cleanup({})["dropped_users"], [])
        later = int(__import__("time").time()) + db.SSO_TTL + 1
        os.utime(os.path.join(self.sso_dir, out["token"] + ".json"), (0, 0))
        with db._lock:
            res = db._cleanup_sso(now=later)
        self.assertEqual(res["dropped_users"], [login["user"]])
        self.assertEqual(res["removed_tokens"], 1)
        self.assertIn(f"DROP USER IF EXISTS '{login['user']}'@'localhost'", self.sql[-1])

    def test_drop_removes_sso_users_of_the_database(self):
        name = db.db_create({"domain": "a.nl", "name": "one"})["name"]
        db.db_sso({"domain": "a.nl", "name": name})
        user = next(iter(db._load_sso()))
        db.drop(name, "a.nl")
        self.assertIn(f"DROP USER IF EXISTS '{user}'@'localhost'", self.sql[-1])
        self.assertEqual(db._load_sso(), {})

    def test_limits_cover_extra_users(self):
        one = db.db_create({"domain": "a.nl", "name": "one"})["name"]
        db.db_user_create({"domain": "a.nl", "name": "rep", "databases": [one]})
        out = db.db_limits({"domain": "a.nl", "max_connections": 15})
        self.assertEqual(out["users"], ["a_nl_one", "a_nl_rep"])
        self.assertIn("ALTER USER 'a_nl_rep'@'localhost' WITH MAX_USER_CONNECTIONS 15", self.sql[-1])
        db.db_user_create({"domain": "a.nl", "name": "late", "databases": [one]})
        self.assertIn("ALTER USER 'a_nl_late'@'localhost' WITH MAX_USER_CONNECTIONS 15", self.sql[-1])

    def test_prefix(self):
        self.assertEqual(db.prefix_for("bakkerij-de-vries.example.com"), "bakkerij_de_vries_ex_")
        self.assertEqual(db.prefix_for("a.nl"), "a_nl_")


if __name__ == "__main__":
    unittest.main()
