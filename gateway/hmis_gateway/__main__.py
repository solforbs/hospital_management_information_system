"""Entry point:  python -m hmis_gateway --config config.json [--check]"""

from __future__ import annotations

import argparse
import json
import logging
import logging.handlers
import signal
import sys
import threading

from . import __version__
from .client import ApiError, HmisClient, Spool, flush
from .drivers import make_driver, serve
from .links import connections, sleep_until

log = logging.getLogger("hmis")


def load_config(path: str) -> dict:
    with open(path, encoding="utf-8") as f:
        config = json.load(f)
    for key in ("server", "token", "instruments"):
        if not config.get(key):
            raise SystemExit(f"config: '{key}' is required")
    codes = [i.get("code") for i in config["instruments"]]
    if len(codes) != len(set(codes)) or not all(codes):
        raise SystemExit("config: every instrument needs a unique 'code' (the same code as in HMIS → Lab analysers)")
    return config


def setup_logging(config: dict) -> None:
    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stdout)]
    if config.get("log_file"):
        handlers.append(logging.handlers.RotatingFileHandler(config["log_file"], maxBytes=5_000_000, backupCount=5, encoding="utf-8"))
    logging.basicConfig(level=getattr(logging, str(config.get("log_level", "INFO")).upper(), logging.INFO),
                        format="%(asctime)s %(levelname)-7s %(name)s: %(message)s", handlers=handlers)


def check(client: HmisClient, config: dict) -> int:
    try:
        info = client.heartbeat()
    except (ApiError, OSError) as e:
        print(f"FAILED to reach HMIS at {client.server}: {e}")
        return 1
    print(f"Connected to HMIS: gateway '{info['gateway']['name']}' at {info['gateway']['hospital']}")
    known = {i["code"]: i for i in info.get("instruments", [])}
    for inst in config["instruments"]:
        remote = known.get(inst["code"])
        if remote:
            print(f"  OK   {inst['code']}: {remote['name']} ({remote['protocol']}), {len(remote['mappings'])} codes mapped")
        else:
            print(f"  MISSING {inst['code']}: not set up in HMIS for this gateway (Lab analysers page)")
    return 0 if all(i["code"] in known for i in config["instruments"]) else 2


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="hmis_gateway", description="HMIS laboratory analyser gateway")
    parser.add_argument("--config", default="config.json")
    parser.add_argument("--check", action="store_true", help="test the connection to HMIS and exit")
    parser.add_argument("--version", action="version", version=__version__)
    args = parser.parse_args(argv)

    config = load_config(args.config)
    setup_logging(config)
    client = HmisClient(config["server"], config["token"], verify_tls=config.get("verify_tls", True))

    if args.check:
        return check(client, config)

    spool = Spool(config.get("spool", "hmis_gateway_spool.db"))
    stop = threading.Event()
    wake_flusher = threading.Event()

    def on_signal(*_):
        log.info("stopping…")
        stop.set()
        wake_flusher.set()

    signal.signal(signal.SIGINT, on_signal)
    if hasattr(signal, "SIGTERM"):
        signal.signal(signal.SIGTERM, on_signal)

    log.info("HMIS gateway %s starting with %d instrument(s); server %s", __version__, len(config["instruments"]), config["server"])
    threads: list[threading.Thread] = []

    def flusher():
        while not stop.is_set():
            try:
                flush(client, spool)
            except Exception:  # noqa: BLE001
                log.exception("flush failed")
            wake_flusher.wait(10)
            wake_flusher.clear()
        flush(client, spool)

    def heartbeat():
        while not stop.is_set():
            try:
                info = client.heartbeat()
                known = {i["code"] for i in info.get("instruments", [])}
                missing = [i["code"] for i in config["instruments"] if i["code"] not in known]
                if missing:
                    log.warning("not set up in HMIS for this gateway: %s", ", ".join(missing))
            except (ApiError, OSError, ValueError) as e:
                log.warning("heartbeat failed: %s (spool: %d waiting)", e, spool.pending_count())
            sleep_until(stop, 60)

    drivers = []
    for inst in config["instruments"]:
        driver = make_driver(inst, client, spool, on_result=wake_flusher.set)
        drivers.append(driver)
        threads.append(threading.Thread(target=serve, args=(driver, connections(inst, stop), stop), name=inst["code"], daemon=True))

    def poll_orders():
        bidirectional = [d for d in drivers if d.config.get("bidirectional")]
        while bidirectional and not stop.is_set():
            for d in bidirectional:
                try:
                    d.queue_orders(client.orders(d.code))
                except (ApiError, OSError, ValueError) as e:
                    log.warning("%s: could not fetch orders (%s)", d.code, e)
            sleep_until(stop, int(config.get("order_poll_seconds", 30)))

    threads += [threading.Thread(target=t, name=t.__name__, daemon=True) for t in (flusher, heartbeat, poll_orders)]
    for t in threads:
        t.start()

    while not stop.is_set():
        stop.wait(1)
    for t in threads:
        t.join(timeout=5)
    log.info("stopped; %d result(s) still waiting in spool", spool.pending_count())
    return 0


if __name__ == "__main__":
    sys.exit(main())
