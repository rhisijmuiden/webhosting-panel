"""Background jobs for long running actions (updates, backups)."""

import json
import os
import threading
import time
import traceback
import uuid

from . import paths
from .registry import ActionError

_lock = threading.Lock()


def _path(job_id):
    return os.path.join(paths.JOBS_DIR, f"{job_id}.json")


def _write(job):
    os.makedirs(paths.JOBS_DIR, mode=0o750, exist_ok=True)
    tmp = _path(job["id"]) + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(job, fh)
    os.replace(tmp, _path(job["id"]))


def read(job_id):
    if not isinstance(job_id, str) or not all(c in "0123456789abcdef-" for c in job_id):
        raise ActionError("invalid_params", "Invalid job id")
    try:
        with open(_path(job_id)) as fh:
            return json.load(fh)
    except FileNotFoundError:
        raise ActionError("not_found", "Job not found")


def start_job(name, fn, params):
    """Run fn(params, log) in a thread. Returns {"job": id} straight away."""
    job = {
        "id": str(uuid.uuid4()),
        "action": name,
        "status": "running",
        "started_at": time.time(),
        "finished_at": None,
        "log": [],
        "result": None,
        "error": None,
    }
    with _lock:
        _write(job)

    def log(line):
        with _lock:
            job["log"].append(str(line)[:2000])
            job["log"] = job["log"][-500:]
            _write(job)

    def worker():
        try:
            result = fn(params, log)
            job["result"] = result if result is not None else {}
            job["status"] = "succeeded"
        except ActionError as exc:
            job["error"] = exc.to_dict()
            job["status"] = "failed"
        except Exception as exc:  # pragma: no cover - defensive
            job["error"] = {"code": "internal", "message": str(exc)}
            job["status"] = "failed"
            log(traceback.format_exc())
        job["finished_at"] = time.time()
        with _lock:
            _write(job)

    threading.Thread(target=worker, daemon=True).start()
    return {"job": job["id"]}
