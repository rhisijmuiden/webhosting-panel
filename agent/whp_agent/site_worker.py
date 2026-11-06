#!/usr/bin/python3 -I
"""File work for customer backups, run as the website's own Linux user.

The agent (root) starts this script with runuser, so reading and writing the
website's files happens with the rights of that user only: a symlink a
customer plants towards /etc leads nowhere, and a restore can never write
outside what the site user may write anyway (including its disk quota).

Python standard library only. Subcommands (ROOT is the website or mail folder):

  scan ROOT              one JSON line per entry: {"p": path, "t": "f|d|l", "s": size, "m": mtime}
  pack ROOT              read NUL separated relative paths on stdin, write a tar stream on stdout
  unpack ROOT            read a tar stream on stdin and extract it into ROOT (tarfile "data" filter)
  prune ROOT             read NUL separated relative paths to keep on stdin, remove everything else

Folders in --exclude (relative, repeatable) are never scanned, packed or pruned.
"""

import argparse
import json
import os
import stat
import sys
import tarfile

# Same rules as whp-backup's excludes.txt: caches and runtime folders are not data.
DEFAULT_EXCLUDES = ("tmp", "sessions", "logs", ".cache", ".wp-cli/cache")


def _excluded(rel, excludes):
    return any(rel == e or rel.startswith(e + "/") for e in excludes)


def _clean_rel(rel):
    """A relative path as stored in a backup: no leading /, no .. parts, no empty parts."""
    if not rel or rel.startswith("/") or "\x00" in rel or "\\" in rel:
        return None
    parts = rel.split("/")
    if any(p in ("", ".", "..") for p in parts):
        return None
    return rel


def scan(root, excludes, out):
    root = os.path.abspath(root)
    if not os.path.isdir(root):
        return
    for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
        rel_dir = os.path.relpath(dirpath, root)
        rel_dir = "" if rel_dir == "." else rel_dir
        keep = []
        for d in sorted(dirnames):
            rel = f"{rel_dir}/{d}" if rel_dir else d
            if _excluded(rel, excludes):
                continue
            full = os.path.join(dirpath, d)
            st = os.lstat(full)
            if stat.S_ISLNK(st.st_mode):
                out.write(json.dumps({"p": rel, "t": "l", "s": 0, "m": int(st.st_mtime)}) + "\n")
                continue
            out.write(json.dumps({"p": rel, "t": "d", "s": 0, "m": int(st.st_mtime)}) + "\n")
            keep.append(d)
        dirnames[:] = keep
        for f in sorted(filenames):
            rel = f"{rel_dir}/{f}" if rel_dir else f
            if _excluded(rel, excludes):
                continue
            try:
                st = os.lstat(os.path.join(dirpath, f))
            except OSError:
                continue
            if stat.S_ISLNK(st.st_mode):
                kind = "l"
            elif stat.S_ISREG(st.st_mode):
                kind = "f"
            else:
                continue  # sockets, fifos, devices: not website data
            out.write(json.dumps({"p": rel, "t": kind, "s": st.st_size if kind == "f" else 0,
                                  "m": int(st.st_mtime)}) + "\n")


def _read_list(stream):
    data = stream.read()
    for raw in data.split(b"\x00"):
        if raw:
            rel = _clean_rel(raw.decode("utf-8", "surrogateescape"))
            if rel is not None:
                yield rel


def pack(root, excludes, stdin, stdout):
    root = os.path.abspath(root)
    with tarfile.open(fileobj=stdout, mode="w|", format=tarfile.PAX_FORMAT) as tar:
        for rel in _read_list(stdin):
            if _excluded(rel, excludes):
                continue
            full = os.path.join(root, rel)
            try:
                st = os.lstat(full)
            except OSError:
                continue  # removed since the scan
            if not (stat.S_ISREG(st.st_mode) or stat.S_ISDIR(st.st_mode) or stat.S_ISLNK(st.st_mode)):
                continue
            # A parent replaced by a symlink since the scan would lead elsewhere: skip.
            parent = os.path.dirname(full)
            if os.path.realpath(parent) != os.path.normpath(parent):
                continue
            info = tar.gettarinfo(full, arcname=rel)
            info.uname = info.gname = ""
            info.uid = info.gid = 0
            if info.isreg():
                try:
                    fd = os.open(full, os.O_RDONLY | os.O_NOFOLLOW)
                except OSError:
                    continue
                with os.fdopen(fd, "rb") as fh:
                    # The size can change while reading; tarfile needs the exact size.
                    info.size = os.fstat(fh.fileno()).st_size
                    tar.addfile(info, _Exact(fh, info.size))
            else:
                tar.addfile(info)


class _Exact:
    """Read exactly n bytes: pad with zeros when the file shrank while packing."""

    def __init__(self, fh, n):
        self.fh, self.left = fh, n

    def read(self, size=-1):
        if self.left <= 0:
            return b""
        size = self.left if size is None or size < 0 else min(size, self.left)
        chunk = self.fh.read(size)
        if not chunk:
            chunk = b"\x00" * size
        self.left -= len(chunk)
        return chunk


def unpack(root, excludes, stdin):
    root = os.path.abspath(root)
    os.makedirs(root, exist_ok=True)
    count = 0
    with tarfile.open(fileobj=stdin, mode="r|") as tar:
        for member in tar:
            rel = _clean_rel(member.name)
            if rel is None or _excluded(rel, excludes):
                continue
            if not (member.isreg() or member.isdir() or member.issym()):
                continue
            target = os.path.join(root, rel)
            # Replace what is in the way: a file where a folder comes, a symlink, ...
            try:
                st = os.lstat(target)
                if member.isdir() and not stat.S_ISDIR(st.st_mode):
                    os.unlink(target)
                elif not member.isdir():
                    if stat.S_ISDIR(st.st_mode) and not stat.S_ISLNK(st.st_mode):
                        _rmtree(target)
                    else:
                        os.unlink(target)
            except FileNotFoundError:
                pass
            # The "data" filter refuses absolute names, .., links that leave ROOT and
            # special files, and strips setuid/setgid bits.
            tar.extract(member, root, filter="data")
            count += 1
    json.dump({"extracted": count}, sys.stderr)


def _rmtree(path):
    for dirpath, dirnames, filenames in os.walk(path, topdown=False, followlinks=False):
        for f in filenames:
            os.unlink(os.path.join(dirpath, f))
        for d in dirnames:
            p = os.path.join(dirpath, d)
            if os.path.islink(p):
                os.unlink(p)
            else:
                os.rmdir(p)
    os.rmdir(path)


def prune(root, excludes, stdin):
    """Remove files and folders that are not in the keep list (mirror a backup)."""
    root = os.path.abspath(root)
    keep = set(_read_list(stdin))
    # Parents of kept entries are kept too.
    for rel in list(keep):
        parts = rel.split("/")
        for i in range(1, len(parts)):
            keep.add("/".join(parts[:i]))
    removed = 0
    for dirpath, dirnames, filenames in os.walk(root, topdown=False, followlinks=False):
        rel_dir = os.path.relpath(dirpath, root)
        rel_dir = "" if rel_dir == "." else rel_dir
        if rel_dir and _excluded(rel_dir, excludes):
            continue
        for f in filenames:
            rel = f"{rel_dir}/{f}" if rel_dir else f
            if rel not in keep and not _excluded(rel, excludes):
                os.unlink(os.path.join(dirpath, f))
                removed += 1
        for d in dirnames:
            rel = f"{rel_dir}/{d}" if rel_dir else d
            full = os.path.join(dirpath, d)
            if rel in keep or _excluded(rel, excludes):
                continue
            if os.path.islink(full):
                os.unlink(full)
                removed += 1
            else:
                try:
                    os.rmdir(full)  # empty by now, unless it holds excluded folders
                    removed += 1
                except OSError:
                    pass
    json.dump({"removed": removed}, sys.stderr)


def main(argv=None):
    ap = argparse.ArgumentParser(prog="site_worker")
    ap.add_argument("command", choices=["scan", "pack", "unpack", "prune"])
    ap.add_argument("root")
    ap.add_argument("--exclude", action="append", default=None)
    args = ap.parse_args(argv)
    excludes = tuple(e.strip("/") for e in (args.exclude if args.exclude is not None else DEFAULT_EXCLUDES) if e)
    os.umask(0o022)
    if args.command == "scan":
        scan(args.root, excludes, sys.stdout)
    elif args.command == "pack":
        pack(args.root, excludes, sys.stdin.buffer, sys.stdout.buffer)
    elif args.command == "unpack":
        unpack(args.root, excludes, sys.stdin.buffer)
    else:
        prune(args.root, excludes, sys.stdin.buffer)
    return 0


if __name__ == "__main__":
    sys.exit(main())
