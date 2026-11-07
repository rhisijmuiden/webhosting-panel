"""Walk one website root and summarise where its disk space goes.

Run by the agent as a separate process with low CPU and I/O priority
(nice + ionice idle class), so a scan of a big site never slows the server:

    python3 diskscan.py <root>      prints the summary as JSON on stdout

It only reads metadata (no file contents), never follows symlinks and opens
every directory relative to its parent with O_NOFOLLOW, so a symlink that a
customer swaps in during the scan cannot lead it outside the site root. It
stays on the filesystem of the root (mount points are counted as empty).

Sizes are the space a file takes on disk (st_blocks * 512), the same number
the disk quota counts. A file with several hard links is counted once.

The result keeps the folder tree, pruned so it stays small for very big sites:
every folder keeps its largest sub folders (MAX_CHILDREN, the rest is summed
into "other"), its largest files (MAX_FILES) and is cut off below MAX_DEPTH
(deeper folders still count towards their parents). The node format is short
because it can hold many thousands of folders:

    {"n": name, "s": bytes, "f": files (all levels), "d": folders (all levels),
     "fs": bytes of the files directly in it, "fc": number of those files,
     "c": [child nodes], "o": [other_bytes, other_count], "top": [[name, bytes, mtime]],
     "x": true when the tree below was cut off}
"""

import heapq
import json
import os
import stat
import sys
import time

MAX_DEPTH = 10
MAX_CHILDREN = 60
MAX_FILES = 12
MAX_LARGEST = 25
MAX_RECURSION = 200
MAX_NODES = 5000
DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC


class Scan:
    def __init__(self, max_entries=20_000_000, max_seconds=3600):
        self.dev = None
        self.seen = set()
        self.largest = []  # min-heap of (bytes, path, mtime)
        self.entries = 0
        self.errors = 0
        self.max_entries = max_entries
        self.deadline = time.monotonic() + max_seconds
        self.partial = False

    def run(self, root):
        fd = os.open(root, DIR_FLAGS)
        try:
            st = os.fstat(fd)
            self.dev = st.st_dev
            node = self._walk(fd, "", "", 0)
        finally:
            os.close(fd)
        node["s"] += st.st_blocks * 512
        limit_nodes(node, MAX_NODES)
        return {
            "tree": node,
            "largest": [{"path": p, "bytes": b, "mtime": m} for b, p, m in sorted(self.largest, reverse=True)],
            "entries": self.entries,
            "errors": self.errors,
            "partial": self.partial,
        }

    def _stop(self):
        if self.entries >= self.max_entries or time.monotonic() > self.deadline:
            self.partial = True
        return self.partial

    def _walk(self, dfd, name, rel, depth):
        node = {"n": name, "s": 0, "f": 0, "d": 0, "fs": 0, "fc": 0, "c": [], "top": []}
        try:
            with os.scandir(dfd) as it:
                entries = list(it)
        except OSError:
            self.errors += 1
            return node
        files = []
        for entry in entries:
            self.entries += 1
            try:
                st = entry.stat(follow_symlinks=False)
            except OSError:
                self.errors += 1
                continue
            size = st.st_blocks * 512
            path = f"{rel}/{entry.name}" if rel else entry.name
            if stat.S_ISDIR(st.st_mode):
                node["d"] += 1
                if st.st_dev != self.dev:
                    continue  # another filesystem mounted inside the site
                child = None
                if depth < MAX_RECURSION and not self._stop():
                    try:
                        cfd = os.open(entry.name, DIR_FLAGS, dir_fd=dfd)
                    except OSError:
                        self.errors += 1
                    else:
                        try:
                            child = self._walk(cfd, entry.name, path, depth + 1)
                        finally:
                            os.close(cfd)
                if child is None:
                    child = {"n": entry.name, "s": 0, "f": 0, "d": 0, "fs": 0, "fc": 0, "c": [], "top": [], "x": True}
                child["s"] += size
                node["s"] += child["s"]
                node["f"] += child["f"]
                node["d"] += child["d"]
                node["c"].append(child)
                continue
            if st.st_nlink > 1:
                key = (st.st_dev, st.st_ino)
                if key in self.seen:
                    size = 0
                else:
                    self.seen.add(key)
            node["s"] += size
            node["f"] += 1
            node["fs"] += size
            node["fc"] += 1
            if stat.S_ISREG(st.st_mode) and size:
                files.append((size, entry.name, int(st.st_mtime)))
                item = (size, path, int(st.st_mtime))
                if len(self.largest) < MAX_LARGEST:
                    heapq.heappush(self.largest, item)
                elif item > self.largest[0]:
                    heapq.heapreplace(self.largest, item)
        node["top"] = [[n, s, m] for s, n, m in heapq.nlargest(MAX_FILES, files)]
        self._prune(node, depth)
        return node

    @staticmethod
    def _prune(node, depth):
        children = sorted(node["c"], key=lambda c: c["s"], reverse=True)
        if depth + 1 >= MAX_DEPTH:
            # Keep the folder names and sizes one level down, without their trees.
            for c in children:
                if c["c"] or c["top"]:
                    c["x"] = True
                c["c"], c["top"] = [], []
                c.pop("o", None)
        keep, rest = children[:MAX_CHILDREN], children[MAX_CHILDREN:]
        node["c"] = keep
        if rest:
            node["o"] = [sum(c["s"] for c in rest), len(rest)]


def limit_nodes(tree, max_nodes):
    """Keep at most max_nodes folders in the whole tree, the largest first;
    the folders that are dropped are summed into their parent's "other"."""
    kept = set()
    heap = [(-c["s"], id(c), c) for c in tree["c"]]
    heapq.heapify(heap)
    while heap and len(kept) < max_nodes:
        _, key, node = heapq.heappop(heap)
        kept.add(key)
        for c in node["c"]:
            heapq.heappush(heap, (-c["s"], id(c), c))
    stack = [tree]
    while stack:
        node = stack.pop()
        dropped = [c for c in node["c"] if id(c) not in kept]
        if dropped:
            other = node.get("o", [0, 0])
            node["o"] = [other[0] + sum(c["s"] for c in dropped), other[1] + len(dropped)]
            node["c"] = [c for c in node["c"] if id(c) in kept]
        stack.extend(node["c"])


def scan(root, **limits):
    result = Scan(**limits).run(root)
    result["root"] = root
    result["scanned_at"] = int(time.time())
    return result


if __name__ == "__main__":  # pragma: no cover - exercised through the agent
    if len(sys.argv) != 2:
        print("usage: diskscan.py <root>", file=sys.stderr)
        sys.exit(2)
    json.dump(scan(sys.argv[1]), sys.stdout, separators=(",", ":"))
