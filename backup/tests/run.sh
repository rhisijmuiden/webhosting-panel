#!/usr/bin/env bash
# Integration tests for whp-backup. Needs restic, rclone, jq, rsync.
# Runs against a local target in a temporary directory. Set
#   WHP_TEST_DB=1                                   to include MariaDB tests (runs as root)
#   WHP_TEST_SFTP=user@host[:port] WHP_TEST_SFTP_KEY=file   for an SFTP target
#   WHP_TEST_FTPS=user@host[:port] WHP_TEST_FTPS_PASSWORD_FILE=file [WHP_TEST_FTPS_TLS=explicit|implicit]
set -Eeuo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
BIN="$HERE/../bin/whp-backup"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

export WHP_ETC="$T/etc" WHP_STATE_DIR="$T/state" WHP_LOG_DIR="$T/log" WHP_BACKUP_LOCK="$T/lock"
mkdir -p "$WHP_ETC/backup" "$WHP_LOG_DIR"
SITES="$T/www" MAIL="$T/vmail"
cat >"$WHP_ETC/backup/backup.conf" <<EOF
SITES_ROOT="$SITES"
MAIL_ROOT="$MAIL"
SYSTEM_PATHS="$T/sysconf"
DATABASES="${WHP_TEST_DB:+yes}"
DB_EXCLUDE="information_schema performance_schema sys mysql"
EOF
[[ -n "${WHP_TEST_DB:-}" ]] || sed -i 's/^DATABASES=.*/DATABASES="no"/' "$WHP_ETC/backup/backup.conf"

pass=0 fail=0
ok() { pass=$((pass + 1)); printf '  ok   %s\n' "$1"; }
ko() { fail=$((fail + 1)); printf '  FAIL %s\n' "$1"; }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$name"; else ko "$name"; fi; }
wb() { "$BIN" --json "$@"; }

reset_fixture() {
	rm -rf "$SITES" "$MAIL" "$T/sysconf"
	mkdir -p "$SITES/example.com/public" "$SITES/shop.example.nl/public" "$MAIL/example.com/info/Maildir/cur" "$T/sysconf"
	echo "hello v1" >"$SITES/example.com/public/index.html"
	echo "shop" >"$SITES/shop.example.nl/public/index.php"
	echo "mail 1" >"$MAIL/example.com/info/Maildir/cur/1"
	echo "panel=1" >"$T/sysconf/panel.conf"
}

run_suite() {
	local tgt="$1"
	echo "== target $tgt"
	local out sid
	reset_fixture

	out="$(wb target test "$tgt")"
	check "$tgt: target reachable and repository present" jq -e '.data.reachable and .data.repository' <<<"$out"

	out="$(wb run --full --target "$tgt")"
	check "$tgt: full backup ok" jq -e '.data.status == "ok"' <<<"$out"
	check "$tgt: full backup covers system, 2 sites, 1 mail domain" \
		jq -e '[.data.targets[0].items[].scope] | (index("system") != null) and (index("site:example.com") != null)
			and (index("site:shop.example.nl") != null) and (index("mail:example.com") != null)' <<<"$out"

	echo "hello v2" >"$SITES/example.com/public/index.html"
	echo "new" >"$SITES/example.com/public/new.html"
	out="$(wb run --incremental --target "$tgt" --scope site:example.com)"
	check "$tgt: incremental backup ok" jq -e '.data.status == "ok"' <<<"$out"
	check "$tgt: incremental only picked up changed files" \
		jq -e '.data.targets[0].items[0] | .files_new == 1 and .files_changed == 1' <<<"$out"

	out="$(wb snapshots --target "$tgt" --scope site:example.com)"
	check "$tgt: two snapshots for site:example.com" jq -e '.data | length == 2' <<<"$out"
	check "$tgt: snapshot kinds are tagged" jq -e '[.data[].kind] == ["incremental","full"]' <<<"$out"
	sid="$(jq -r '.data[1].short_id' <<<"$out")"

	out="$(wb restore --target "$tgt" --snapshot "$sid" --to "$T/restore-$tgt")"
	check "$tgt: restore to directory" grep -qx "hello v1" "$T/restore-$tgt$SITES/example.com/public/index.html"

	out="$(wb restore --target "$tgt" --snapshot "$sid" --in-place)"
	check "$tgt: in-place restore brings back v1" grep -qx "hello v1" "$SITES/example.com/public/index.html"
	check "$tgt: in-place restore removes files newer than the snapshot" test ! -e "$SITES/example.com/public/new.html"
	check "$tgt: in-place restore made a pre-restore snapshot" jq -e '.data.pre_restore_snapshot != null' <<<"$out"

	local pre
	pre="$(jq -r '.data.pre_restore_snapshot[0:8]' <<<"$out")"
	wb restore --target "$tgt" --snapshot "$pre" --in-place >/dev/null
	check "$tgt: undo via pre-restore snapshot" grep -qx "hello v2" "$SITES/example.com/public/index.html"

	out="$(wb restore --target "$tgt" --snapshot "$sid" --in-place --include "$SITES/example.com/public/index.html")"
	check "$tgt: single file restore" grep -qx "hello v1" "$SITES/example.com/public/index.html"
	check "$tgt: single file restore leaves other files alone" test -e "$SITES/example.com/public/new.html"

	if [[ -n "${WHP_TEST_DB:-}" ]]; then
		mariadb -e "DROP DATABASE IF EXISTS whp_test_$tgt; CREATE DATABASE whp_test_$tgt;
			CREATE TABLE whp_test_$tgt.t (id INT PRIMARY KEY, v VARCHAR(20)); INSERT INTO whp_test_$tgt.t VALUES (1,'one'),(2,'two');"
		out="$(wb run --target "$tgt" --scope "db:whp_test_$tgt")"
		check "$tgt: database backup ok" jq -e '.data.status == "ok"' <<<"$out"
		mariadb -e "DELETE FROM whp_test_$tgt.t; INSERT INTO whp_test_$tgt.t VALUES (3,'three');"
		sid="$(wb snapshots --target "$tgt" --scope "db:whp_test_$tgt" | jq -r '.data[0].short_id')"
		wb restore --target "$tgt" --snapshot "$sid" --in-place >/dev/null
		check "$tgt: database restore in place" test "$(mariadb -N -e "SELECT GROUP_CONCAT(v ORDER BY id) FROM whp_test_$tgt.t")" = "one,two"
		wb restore --target "$tgt" --snapshot "$sid" --in-place --database "whp_test_${tgt}_copy" >/dev/null
		check "$tgt: database restore under another name" test "$(mariadb -N -e "SELECT COUNT(*) FROM whp_test_${tgt}_copy.t")" = "2"
		out="$(wb run --target "$tgt" --scope "db:does_not_exist")" || true
		check "$tgt: failing dump is reported as failed" jq -e '.data.status == "failed"' <<<"$out"
		check "$tgt: failing dump leaves no snapshot" \
			jq -e '.data | length == 0' <<<"$(wb snapshots --target "$tgt" --scope db:does_not_exist)"
		mariadb -e "DROP DATABASE whp_test_$tgt; DROP DATABASE whp_test_${tgt}_copy;"
	fi

	out="$(wb prune --target "$tgt")"
	check "$tgt: prune runs" jq -e '.ok' <<<"$out"
	out="$(wb check --target "$tgt")"
	check "$tgt: repository check healthy" jq -e '.data.healthy' <<<"$out"
}

echo "== target management"
out="$(wb target add --name local1 --type local --path "$T/remote1")"
check "add local target" jq -e '.ok and .data.initialized' <<<"$out"
check "repository key is private" test "$(stat -c %a "$WHP_ETC/backup/targets/local1/repo.key")" = "600"
out="$(wb target add --name local1 --type local --path "$T/remote1" 2>/dev/null)" || true
check "duplicate target refused" jq -e '.ok == false and .error.code == "conflict"' <<<"$out"
out="$(wb target add --name 'bad name' --type local --path x 2>/dev/null)" || true
check "invalid name refused" jq -e '.error.code == "invalid_params"' <<<"$out"
out="$(echo pw >"$T/pw"; wb target add --name ftp1 --type ftp --host h --user u --password-file "$T/pw" 2>/dev/null --path x)" || true
check "plain FTP refused without --allow-plain-ftp" jq -e '.error.code == "invalid_params"' <<<"$out"
out="$(wb restore --target local1 --snapshot 'abc;rm' --to /tmp 2>/dev/null)" || true
check "bad snapshot id refused" jq -e '.error.code == "invalid_params"' <<<"$out"
out="$(wb run --target local1 --scope 'site:../etc' 2>/dev/null)" || true
check "path traversal in scope refused" jq -e '.error.code == "invalid_params"' <<<"$out"

run_suite local1

echo "== disaster recovery: connect a fresh server to the existing repository"
wb target export-key local1 | jq -r .data.repository_key >"$T/key"
DR="$T/dr"
mkdir -p "$DR/etc/backup"
cp "$WHP_ETC/backup/backup.conf" "$DR/etc/backup/"
out="$(WHP_ETC="$DR/etc" WHP_STATE_DIR="$DR/state" "$BIN" --json target add --name restored --type local --path "$T/remote1" --repo-key-file "$T/key")"
check "new server accepts the existing repository with its key" jq -e '.data.initialized' <<<"$out"
out="$(WHP_ETC="$DR/etc" WHP_STATE_DIR="$DR/state" "$BIN" --json snapshots --target restored)"
check "new server sees all snapshots" jq -e '.data | length >= 5' <<<"$out"
head -c 48 /dev/urandom | base64 >"$T/wrongkey"
out="$(WHP_ETC="$DR/etc" WHP_STATE_DIR="$DR/state" "$BIN" --json target add --name wrong --type local --path "$T/remote1" --repo-key-file "$T/wrongkey" 2>/dev/null)" || true
check "wrong key is refused" jq -e '.ok == false' <<<"$out"

if [[ -n "${WHP_TEST_SFTP:-}" ]]; then
	hostpart="${WHP_TEST_SFTP#*@}"
	wb target add --name sftp1 --type sftp --host "${hostpart%%:*}" --port "${hostpart##*:}" \
		--user "${WHP_TEST_SFTP%%@*}" --ssh-key "$WHP_TEST_SFTP_KEY" --path "whp-test-$$" >/dev/null
	run_suite sftp1
fi

if [[ -n "${WHP_TEST_FTPS:-}" ]]; then
	hostpart="${WHP_TEST_FTPS#*@}"
	wb target add --name ftps1 --type ftps --tls "${WHP_TEST_FTPS_TLS:-explicit}" --host "${hostpart%%:*}" \
		--port "${hostpart##*:}" --user "${WHP_TEST_FTPS%%@*}" \
		--password-file "$WHP_TEST_FTPS_PASSWORD_FILE" --path "whp-test-$$" >/dev/null
	run_suite ftps1
fi

out="$(wb status)"
check "status reports last run and targets" jq -e '.data.last_run.status and (.data.targets | length >= 1)' <<<"$out"

echo
echo "passed: $pass, failed: $fail"
[[ $fail -eq 0 ]]
