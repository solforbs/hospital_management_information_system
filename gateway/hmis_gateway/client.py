"""HTTPS client for the HMIS LIS API, with a store-and-forward spool.

Results are written to a local SQLite spool before sending, so an internet
outage never loses a result: the spool is retried until HMIS confirms it.
"""

from __future__ import annotations

import json
import logging
import sqlite3
import ssl
import threading
import time
import urllib.error
import urllib.request

from . import __version__

log = logging.getLogger("hmis.client")


class ApiError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(f"HTTP {status}: {message}")
        self.status = status


class HmisClient:
    def __init__(self, server: str, token: str, timeout: float = 20.0, verify_tls: bool = True):
        self.server = server.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.context = ssl.create_default_context() if verify_tls else ssl._create_unverified_context()  # noqa: S323

    def _request(self, method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
        data = json.dumps(body).encode("utf-8") if body is not None else None
        req = urllib.request.Request(self.server + path, data=data, method=method, headers={
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": f"hmis-gateway/{__version__}",
            "X-Gateway-Version": __version__,
        })
        try:
            ctx = self.context if self.server.startswith("https") else None
            with urllib.request.urlopen(req, timeout=self.timeout, context=ctx) as resp:  # noqa: S310
                return resp.status, json.loads(resp.read() or b"{}")
        except urllib.error.HTTPError as e:
            try:
                payload = json.loads(e.read() or b"{}")
            except ValueError:
                payload = {}
            raise ApiError(e.code, payload.get("message", e.reason)) from None

    def heartbeat(self) -> dict:
        return self._request("POST", "/heartbeat", {"version": __version__})[1]

    def orders(self, instrument: str) -> list[dict]:
        return self._request("GET", f"/orders?instrument={urllib.request.quote(instrument)}")[1].get("orders", [])

    def order_for(self, instrument: str, sample_id: str) -> list[dict]:
        path = f"/orders/{urllib.request.quote(sample_id, safe='')}?instrument={urllib.request.quote(instrument)}"
        return self._request("GET", path)[1].get("orders", [])

    def post_results(self, instrument: str, sample_id: str, results: list[dict], raw: str | None) -> tuple[int, dict]:
        return self._request("POST", "/results", {"instrument": instrument, "sample_id": sample_id,
                                                  "results": results, "raw": (raw or "")[:60000] or None})


class Spool:
    """Durable queue of results waiting to reach HMIS, plus orders already sent."""

    def __init__(self, path: str):
        self.db = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self.lock = threading.Lock()
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("""CREATE TABLE IF NOT EXISTS outbox (
            id INTEGER PRIMARY KEY, instrument TEXT, sample_id TEXT, results TEXT, raw TEXT,
            attempts INTEGER DEFAULT 0, last_error TEXT, created_at REAL, next_try REAL)""")
        self.db.execute("""CREATE TABLE IF NOT EXISTS dead (
            id INTEGER PRIMARY KEY, instrument TEXT, sample_id TEXT, results TEXT, raw TEXT, error TEXT, created_at REAL)""")
        self.db.execute("CREATE TABLE IF NOT EXISTS sent_orders (instrument TEXT, sample_id TEXT, sent_at REAL, PRIMARY KEY (instrument, sample_id))")

    def add(self, instrument: str, sample_id: str, results: list[dict], raw: str | None) -> int:
        with self.lock:
            cur = self.db.execute("INSERT INTO outbox (instrument, sample_id, results, raw, created_at, next_try) VALUES (?,?,?,?,?,?)",
                                  (instrument, sample_id, json.dumps(results), raw, time.time(), 0))
            return cur.lastrowid

    def due(self, limit: int = 50) -> list[tuple]:
        with self.lock:
            return self.db.execute("SELECT id, instrument, sample_id, results, raw, attempts FROM outbox WHERE next_try <= ? ORDER BY id LIMIT ?",
                                   (time.time(), limit)).fetchall()

    def done(self, row_id: int) -> None:
        with self.lock:
            self.db.execute("DELETE FROM outbox WHERE id = ?", (row_id,))

    def retry_later(self, row_id: int, attempts: int, error: str) -> None:
        delay = min(30 * (2 ** min(attempts, 6)), 1800)  # 30 s .. 30 min
        with self.lock:
            self.db.execute("UPDATE outbox SET attempts = ?, last_error = ?, next_try = ? WHERE id = ?", (attempts + 1, error[:500], time.time() + delay, row_id))

    def bury(self, row: tuple, error: str) -> None:
        row_id, instrument, sample_id, results, raw, _ = row
        with self.lock:
            self.db.execute("INSERT INTO dead (instrument, sample_id, results, raw, error, created_at) VALUES (?,?,?,?,?,?)",
                            (instrument, sample_id, results, raw, error[:500], time.time()))
            self.db.execute("DELETE FROM outbox WHERE id = ?", (row_id,))

    def pending_count(self) -> int:
        with self.lock:
            return self.db.execute("SELECT COUNT(*) FROM outbox").fetchone()[0]

    def was_sent(self, instrument: str, sample_id: str, within: float = 86400) -> bool:
        with self.lock:
            row = self.db.execute("SELECT sent_at FROM sent_orders WHERE instrument = ? AND sample_id = ?", (instrument, sample_id)).fetchone()
        return bool(row and row[0] > time.time() - within)

    def mark_sent(self, instrument: str, sample_id: str) -> None:
        with self.lock:
            self.db.execute("INSERT OR REPLACE INTO sent_orders VALUES (?,?,?)", (instrument, sample_id, time.time()))


def flush(client: HmisClient, spool: Spool) -> int:
    """Sends due spooled results. Returns how many were delivered."""
    delivered = 0
    for row in spool.due():
        row_id, instrument, sample_id, results, raw, attempts = row
        try:
            status, outcome = client.post_results(instrument, sample_id, json.loads(results), raw)
            spool.done(row_id)
            delivered += 1
            level = logging.INFO if status == 200 else logging.WARNING
            log.log(level, "%s %s -> %s: %s", instrument, sample_id, outcome.get("status"), outcome.get("detail"))
        except ApiError as e:
            if e.status == 422:  # malformed: will never succeed
                spool.bury(row, str(e))
                log.error("%s %s rejected as invalid, moved to dead letters: %s", instrument, sample_id, e)
            else:  # 401/404 (config) or 5xx: keep and retry
                spool.retry_later(row_id, attempts, str(e))
                log.error("%s %s not delivered (%s); will retry", instrument, sample_id, e)
        except (OSError, ValueError) as e:  # network down, timeout, bad JSON
            spool.retry_later(row_id, attempts, str(e))
            log.warning("HMIS unreachable (%s); %d result(s) waiting in spool", e, spool.pending_count())
            break
    return delivered
