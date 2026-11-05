"""Protocol sessions with one connected analyser (ASTM E1381/E1394 or HL7 v2 MLLP)."""

from __future__ import annotations

import logging
import queue
import time

from . import astm, hl7
from .client import ApiError, HmisClient, Spool
from .links import Link, LinkClosed

log = logging.getLogger("hmis.driver")


class Driver:
    """Shared plumbing: results go to the spool; orders come from HMIS."""

    def __init__(self, config: dict, client: HmisClient, spool: Spool, on_result=None):
        self.config = config
        self.code = config["code"]
        self.client = client
        self.spool = spool
        self.on_result = on_result or (lambda: None)
        self.outbound: "queue.Queue[dict]" = queue.Queue()

    def store(self, sample_id: str, results: list[dict], raw: str) -> None:
        self.spool.add(self.code, sample_id, results, raw)
        log.info("%s: %d result(s) for sample %s queued for HMIS", self.code, len(results), sample_id)
        self.on_result()

    def lookup(self, sample_id: str) -> list[dict]:
        try:
            return self.client.order_for(self.code, sample_id)
        except (ApiError, OSError, ValueError) as e:
            log.warning("%s: query for %s failed (%s); answering 'no information'", self.code, sample_id, e)
            return []

    def queue_orders(self, orders: list[dict]) -> None:
        for order in orders:
            if not self.spool.was_sent(self.code, order["sample_id"]):
                self.outbound.put(order)

    def run(self, link: Link, stop) -> None:
        raise NotImplementedError


class AstmDriver(Driver):
    FRAME_TIMEOUT = 30.0  # E1381: receiver waits up to 30 s for the next frame
    ACK_TIMEOUT = 15.0

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.buffer = b""

    # -- low level -----------------------------------------------------------

    def _read_byte_or_frame(self, link: Link, timeout: float) -> bytes:
        """Returns one control byte (ENQ/EOT/ACK/NAK) or one complete frame, or b'' on timeout."""
        deadline = time.time() + timeout
        while True:
            if self.buffer:
                head = self.buffer[:1]
                if head in (astm.ENQ, astm.EOT, astm.ACK, astm.NAK):
                    self.buffer = self.buffer[1:]
                    return head
                if head == astm.STX:
                    end = min((p for p in (self.buffer.find(astm.ETX), self.buffer.find(astm.ETB)) if p != -1), default=-1)
                    if end != -1 and len(self.buffer) >= end + 5:
                        frame, self.buffer = self.buffer[:end + 5], self.buffer[end + 5:]
                        return frame
                else:
                    self.buffer = self.buffer[1:]  # noise between frames
                    continue
            remaining = deadline - time.time()
            if remaining <= 0:
                return b""
            self.buffer += link.read(4096, timeout=min(remaining, 1.0))

    def receive(self, link: Link) -> list[str]:
        """After the analyser's ENQ: ACK it, collect records until EOT."""
        link.write(astm.ACK)
        records: list[str] = []
        partial = b""
        while True:
            item = self._read_byte_or_frame(link, self.FRAME_TIMEOUT)
            if item == b"":
                log.warning("%s: timed out waiting for the next frame; discarding partial message", self.code)
                return []
            if item == astm.EOT:
                return records
            if item == astm.ENQ:  # analyser restarted the transmission
                link.write(astm.ACK)
                records, partial = [], b""
                continue
            if item.startswith(astm.STX):
                try:
                    frame = astm.parse_frame(item)
                except astm.FrameError as e:
                    log.warning("%s: %s; sending NAK", self.code, e)
                    link.write(astm.NAK)
                    continue
                link.write(astm.ACK)
                partial += frame.text
                if frame.final:
                    records.extend(r for r in partial.decode("latin-1").split("\r") if r)
                    partial = b""

    def send(self, link: Link, records: list[str]) -> bool:
        """Host -> analyser transmission. Returns False if the analyser did not accept it."""
        for attempt in range(6):
            link.write(astm.ENQ)
            reply = self._read_byte_or_frame(link, self.ACK_TIMEOUT)
            if reply == astm.ACK:
                break
            if reply == astm.ENQ:  # contention: the analyser has priority, take its message first
                self.handle_message(link, self.receive(link))
            time.sleep(10 if reply == astm.NAK else 1)
        else:
            log.warning("%s: analyser did not accept our ENQ", self.code)
            return False

        for frame in astm.build_message_frames(records):
            for _ in range(6):
                link.write(frame)
                reply = self._read_byte_or_frame(link, self.ACK_TIMEOUT)
                if reply == astm.ACK:
                    break
                if reply == astm.EOT:  # receiver interrupt request
                    link.write(astm.EOT)
                    return False
            else:
                link.write(astm.EOT)
                return False
        link.write(astm.EOT)
        return True

    # -- application ---------------------------------------------------------

    def handle_message(self, link: Link, records: list[str]) -> None:
        if not records:
            return
        raw = "\r".join(records)
        message = astm.parse_message(records)
        for sample_id, results in message.results.items():
            self.store(sample_id, [r.as_api() for r in results], raw)
        for sample_id in message.queries:
            orders = self.lookup(sample_id)
            reply = astm.build_order_records(orders) if orders else astm.build_no_order_records(sample_id)
            if self.send(link, reply):
                log.info("%s: answered query for %s (%s)", self.code, sample_id, "order sent" if orders else "no order")
                for o in orders:
                    self.spool.mark_sent(self.code, o["sample_id"])

    def run(self, link: Link, stop) -> None:
        self.buffer = b""
        while not stop.is_set():
            item = self._read_byte_or_frame(link, 1.0)
            if item == astm.ENQ:
                self.handle_message(link, self.receive(link))
            elif item == b"" and not self.outbound.empty():
                orders = []
                while not self.outbound.empty() and len(orders) < 20:
                    orders.append(self.outbound.get_nowait())
                if self.send(link, astm.build_order_records(orders)):
                    for o in orders:
                        self.spool.mark_sent(self.code, o["sample_id"])
                    log.info("%s: sent %d order(s) to analyser", self.code, len(orders))


class Hl7Driver(Driver):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.buffer = b""
        self.control = int(time.time()) % 100000

    def _messages(self, link: Link, timeout: float) -> list[str]:
        self.buffer += link.read(65536, timeout=timeout)
        messages, self.buffer = hl7.unframe(self.buffer)
        return messages

    def handle(self, link: Link, text: str) -> None:
        try:
            msg = hl7.parse(text)
        except ValueError as e:
            log.warning("%s: ignored unreadable message (%s)", self.code, e)
            return

        kind = msg.type.split("^")[0]
        if kind == "ORU":
            groups = hl7.parse_oru(msg, self.config.get("hl7_code_component", "auto"))
            for group in groups:
                self.store(group.sample_id, [r.as_api() for r in group.results], text)
            link.write(hl7.frame(hl7.ack(msg, "AA")))
            if not groups:
                log.info("%s: ORU %s had no usable results", self.code, msg.control_id)
        elif kind in ("QRY", "QBP", "ORM") and self.config.get("bidirectional"):
            sample_id = (msg.field("QRD", 8) or msg.field("QPD", 3) or msg.field("OBR", 3)).split(msg.component)[0]
            link.write(hl7.frame(hl7.ack(msg, "AA")))
            for order in self.lookup(sample_id) if sample_id else []:
                self.push(link, order)
        else:
            link.write(hl7.frame(hl7.ack(msg, "AA")))

    def push(self, link: Link, order: dict) -> bool:
        self.control += 1
        link.write(hl7.frame(hl7.order_message(order, f"HMIS{self.control}", self.config.get("name", ""))))
        deadline = time.time() + 10
        while time.time() < deadline:
            for text in self._messages(link, 1.0):
                try:
                    reply = hl7.parse(text)
                except ValueError:
                    continue
                if reply.type.startswith("ACK"):
                    accepted = reply.field("MSA", 1) in ("AA", "CA")
                    if accepted:
                        self.spool.mark_sent(self.code, order["sample_id"])
                    return accepted
                self.handle(link, text)
        log.warning("%s: no ACK for order %s", self.code, order["sample_id"])
        return False

    def run(self, link: Link, stop) -> None:
        self.buffer = b""
        while not stop.is_set():
            messages = self._messages(link, 1.0)
            for text in messages:
                self.handle(link, text)
            if not messages and not self.outbound.empty():
                self.push(link, self.outbound.get_nowait())


def make_driver(config: dict, client: HmisClient, spool: Spool, on_result=None) -> Driver:
    protocol = config.get("protocol", "astm").lower()
    if protocol == "hl7":
        return Hl7Driver(config, client, spool, on_result)
    if protocol == "astm":
        return AstmDriver(config, client, spool, on_result)
    raise SystemExit(f"Instrument {config['code']}: unknown protocol {protocol!r} (use 'astm' or 'hl7')")


def serve(driver: Driver, connections, stop) -> None:
    """Runs the driver over successive connections until stop."""
    for link in connections:
        log.info("%s: connected (%s)", driver.code, getattr(link, "peer", "?"))
        try:
            driver.run(link, stop)
        except LinkClosed as e:
            log.warning("%s: disconnected (%s)", driver.code, e)
        except Exception:  # noqa: BLE001 - never let one analyser take the gateway down
            log.exception("%s: unexpected error; reconnecting", driver.code)
        finally:
            link.close()
        if stop.is_set():
            break
